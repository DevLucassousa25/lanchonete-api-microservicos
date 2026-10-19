"""
Teste de fumaça da API inteira, passando pelo gateway.
Uso:  python scripts/smoke_test.py [http://localhost:8080] [admin@lanchonete.dev] [admin12345]

Funciona com PAGAMENTO_GATEWAY=fake (confirma o Pix pelo webhook)
ou mercadopago com MERCADOPAGO_TEST_PAYER_NAME=APRO (aguarda a aprovação automática).
"""
import json, sys, time, uuid, urllib.request, urllib.error

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://localhost:8080'
ADMIN = (sys.argv[2] if len(sys.argv) > 2 else 'admin@lanchonete.dev',
         sys.argv[3] if len(sys.argv) > 3 else 'admin12345')
ok = 0


def req(metodo, caminho, corpo=None, token=None, esperado=None):
    global ok
    dados = json.dumps(corpo).encode() if corpo is not None else None
    r = urllib.request.Request(BASE + caminho, data=dados, method=metodo)
    r.add_header('Accept', 'application/json')
    if dados:
        r.add_header('Content-Type', 'application/json')
    if token:
        r.add_header('Authorization', f'Bearer {token}')
    try:
        with urllib.request.urlopen(r, timeout=15) as resp:
            status, txt = resp.status, resp.read().decode()
    except urllib.error.HTTPError as e:
        status, txt = e.code, e.read().decode()
    body = json.loads(txt) if txt else None
    if esperado is not None:
        assert status == esperado, f'{metodo} {caminho}: esperado {esperado}, veio {status}: {txt[:300]}'
        ok += 1
    return status, body


def passo(t):
    print(f'\n== {t}')


passo('Público: cardápio e produtos sem login')
_, b = req('GET', '/api/cardapio', esperado=200)
assert b['data'] and b['data'][0]['produtos'][0]['preco_centavos'] > 0
req('GET', '/api/produtos?busca=x&per_page=2', esperado=200)
req('POST', '/api/produtos/cotacao', {'ids': [1]}, esperado=404)  # rota interna bloqueada no gateway
print('ok')

passo('Cadastro e login de cliente')
email = f'cliente.{uuid.uuid4().hex[:8]}@teste.dev'
cad = {'nome': 'Maria Teste', 'email': email, 'senha': 'segredo123', 'senha_confirmation': 'segredo123', 'telefone': '85999990000'}
req('POST', '/api/auth/registrar', {**cad, 'senha_confirmation': 'outra'}, esperado=422)
_, b = req('POST', '/api/auth/registrar', cad, esperado=201)
assert b['token_type'] == 'Bearer' and 'senha' not in b['usuario']
req('POST', '/api/auth/registrar', cad, esperado=422)  # e-mail duplicado
req('POST', '/api/auth/login', {'email': email, 'senha': 'errada'}, esperado=401)
_, b = req('POST', '/api/auth/login', {'email': email, 'senha': 'segredo123'}, esperado=200)
tk = b['access_token']
print('ok')

passo('Perfil e endereços')
req('GET', '/api/me', esperado=401)
req('GET', '/api/me', token='abc.def.ghi', esperado=401)
_, b = req('GET', '/api/me', token=tk, esperado=200)
assert b['data']['email'] == email
cpf = str(uuid.uuid4().int)[:11]
_, b = req('PATCH', '/api/me', {'telefone': '85911112222', 'cpf': cpf}, token=tk, esperado=200)
assert b['data']['cpf'] == cpf
end = {'apelido': 'Casa', 'cep': '60000000', 'logradouro': 'Rua das Flores', 'numero': '123',
       'bairro': 'Centro', 'cidade': 'Fortaleza', 'uf': 'ce'}
_, b = req('POST', '/api/me/enderecos', end, token=tk, esperado=201)
end_id = b['data']['id']
assert b['data']['principal'] is True and b['data']['uf'] == 'CE'
req('PATCH', f'/api/me/enderecos/{end_id}', {'complemento': 'Apto 2'}, token=tk, esperado=200)
req('PUT', '/api/me/senha', {'senha_atual': 'x', 'nova_senha': 'novasenha1', 'nova_senha_confirmation': 'novasenha1'}, token=tk, esperado=422)
print('ok')

passo('Permissões: cliente não administra')
req('POST', '/api/produtos', {'nome': 'X'}, token=tk, esperado=403)
req('GET', '/api/clientes', token=tk, esperado=403)
req('GET', '/api/cozinha', token=tk, esperado=403)
print('ok')

passo('Admin: categorias, produtos e preços')
_, b = req('POST', '/api/auth/login', {'email': ADMIN[0], 'senha': ADMIN[1]}, esperado=200)
adm = b['access_token']
_, b = req('POST', '/api/categorias', {'nome': f'Sobremesas {uuid.uuid4().hex[:4]}', 'ordem': 3}, token=adm, esperado=201)
cat_id = b['data']['id']
req('POST', '/api/produtos', {'categoria_id': cat_id, 'nome': 'Pudim'}, token=adm, esperado=422)  # sem preço
_, b = req('POST', '/api/produtos', {'categoria_id': cat_id, 'nome': 'Pudim', 'descricao': 'Fatia',
                                     'preco_centavos': 700}, token=adm, esperado=201)
prod = b['data']
assert prod['preco_centavos'] == 700 and prod['preco'] == '7,00'
req('PATCH', f"/api/produtos/{prod['id']}", {'descricao': 'Fatia generosa'}, token=adm, esperado=200)
# reajuste imediato + um agendado
req('POST', f"/api/produtos/{prod['id']}/precos", {'valor_centavos': 750, 'motivo': 'Reajuste'}, token=adm, esperado=201)
_, b = req('POST', f"/api/produtos/{prod['id']}/precos", {'valor_centavos': 900, 'inicio_vigencia': '2099-01-01 00:00:00',
                                                          'motivo': 'Agendado'}, token=adm, esperado=201)
agendado_id = b['data']['id']
_, b = req('GET', f"/api/produtos/{prod['id']}/precos", token=adm, esperado=200)
situacoes = [p['situacao'] for p in b['data']]
assert situacoes == ['agendado', 'vigente', 'historico'], situacoes
_, b = req('GET', f"/api/produtos/{prod['id']}", esperado=200)
assert b['data']['preco_centavos'] == 750 and b['data']['proximo_preco']['valor_centavos'] == 900
historico_id = [p for p in req('GET', f"/api/produtos/{prod['id']}/precos", token=adm)[1]['data'] if p['situacao'] == 'historico'][0]['id']
req('DELETE', f"/api/produtos/{prod['id']}/precos/{historico_id}", token=adm, esperado=409)
req('DELETE', f"/api/produtos/{prod['id']}/precos/{agendado_id}", token=adm, esperado=204)
req('DELETE', f'/api/categorias/{cat_id}', token=adm, esperado=409)  # tem produto
_, b = req('GET', '/api/clientes?busca=Maria', token=adm, esperado=200)
assert any(c['email'] == email for c in b['data'])
print('ok')

passo('Pedido com delivery (endereço vem do serviço de contas)')
req('POST', '/api/pedidos', {'tipo_entrega': 'delivery', 'itens': [{'produto_id': 1, 'quantidade': 1}]}, token=tk, esperado=422)
req('POST', '/api/pedidos', {'tipo_entrega': 'delivery', 'endereco_id': 999999,
                             'itens': [{'produto_id': 1, 'quantidade': 1}]}, token=tk, esperado=422)
_, b = req('POST', '/api/pedidos', {'tipo_entrega': 'delivery', 'endereco_id': end_id, 'observacao': 'Sem cebola',
                                    'itens': [{'produto_id': prod['id'], 'quantidade': 2}, {'produto_id': 1, 'quantidade': 1}]},
           token=tk, esperado=201)
ped = b['data']
assert ped['total_centavos'] == 750 * 2 + 1800, ped['total_centavos']
assert 'Rua das Flores, 123 (Apto 2)' in ped['endereco'] and ped['status'] == 'aguardando_pagamento'
codigo = ped['codigo']
_, b = req('GET', '/api/pedidos', token=tk, esperado=200)
assert b['data'][0]['codigo'] == codigo
print('ok')

passo('Outro cliente não vê o pedido nem o Pix')
cad2 = {**cad, 'email': f'outro.{uuid.uuid4().hex[:8]}@teste.dev'}
tk2 = req('POST', '/api/auth/registrar', cad2, esperado=201)[1]['access_token']
req('GET', f'/api/pedidos/{codigo}', token=tk2, esperado=403)
req('GET', f'/api/pedidos/{codigo}', token='x.y.z', esperado=401)       # token lixo → 401 (não 500)
req('GET', '/api/pedidos/nao-e-uuid', token=tk2, esperado=404)            # código inválido → 404 (não 500)
req('GET', f'/api/pedidos/{uuid.uuid4()}', token=tk2, esperado=404)
_, b = req('GET', '/api/pedidos', token=tk2, esperado=200)
assert b['data'] == []
print('ok')

passo('Pix gerado e pagamento confirmado')
pag = None
for _ in range(20):
    s, b = req('GET', f'/api/pagamentos/{codigo}', token=tk)
    if s == 200:
        pag = b['data']; break
    time.sleep(0.5)
assert pag and pag['pix_copia_e_cola'], 'Pix não foi gerado'
req('GET', f'/api/pagamentos/{codigo}', token=tk2, esperado=403)
print('gateway:', pag['gateway'])
if pag['gateway'] == 'fake':
    req('POST', '/api/webhooks/pix', {'gateway_id': pag['gateway_id'], 'status': 'aprovado'}, esperado=200)
for _ in range(60):
    status = req('GET', f'/api/pedidos/{codigo}', token=tk)[1]['data']['status']
    if status != 'aguardando_pagamento':
        break
    time.sleep(1)
assert status == 'pago', status
print('ok — pedido pago')

passo('Cozinha (admin)')
_, b = req('GET', '/api/cozinha', token=adm, esperado=200)
assert any(p['codigo'] == codigo for p in b['data']['pago'])
for esperado in ['em_preparo', 'pronto', 'entregue']:
    _, b = req('POST', f'/api/pedidos/{codigo}/avancar', token=adm, esperado=200)
    assert b['data']['status'] == esperado
req('POST', f'/api/pedidos/{codigo}/avancar', token=adm, esperado=409)
print('ok')

passo('Refresh e logout revogam o token')
_, b = req('POST', '/api/auth/refresh', token=tk, esperado=200)
novo = b['access_token']
req('GET', '/api/me', token=tk, esperado=401)        # antigo revogado
req('GET', '/api/pedidos', token=novo, esperado=200)
req('POST', '/api/auth/logout', token=novo, esperado=200)
req('GET', '/api/pedidos', token=novo, esperado=401)  # revogado também nos OUTROS serviços
print('ok')

passo('Limpeza')
req('DELETE', f"/api/produtos/{prod['id']}", token=adm, esperado=204)
req('GET', f"/api/produtos/{prod['id']}", esperado=404)
req('DELETE', f'/api/categorias/{cat_id}', token=adm, esperado=204)
print('ok')

print(f'\nTUDO OK — {ok} verificações passaram.')
