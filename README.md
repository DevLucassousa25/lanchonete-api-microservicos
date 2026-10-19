# Lanchonete API — microserviços em Laravel

API REST para cardápio online e pedidos de uma lanchonete, pensada para ser consumida por
**qualquer aplicação** (app mobile, site, totem, parceiro). Sem front-end acoplado.

Laravel 12 · PHP 8.3 · PostgreSQL · RabbitMQ · Redis · Nginx (API Gateway) · Docker · JWT · Mercado Pago (Pix)

**Documentação interativa (Swagger):** http://localhost:8080/docs

## Arquitetura

```
                      ┌──────────── gateway (nginx :8080) ────────────┐
   apps clientes ───► │  /api/auth /api/me /api/clientes   → contas   │
                      │  /api/cardapio /api/produtos ...   → catalogo │
                      │  /api/pedidos /api/cozinha         → pedidos  │
                      │  /api/pagamentos /api/webhooks     → pagamentos│
                      │  /docs                             → Swagger  │
                      └───────────────────────────────────────────────┘

 contas [pg]     catalogo [pg]      pedidos [pg] ──HTTP──► catalogo (preço vigente)
   │ emite JWT                        │        └──HTTP──► contas   (endereço do cliente)
   │                                  │ pedido.criado
   └─ Redis: tokens revogados ◄───────┼──────── RabbitMQ (exchange "lanchonete.eventos")
      (compartilhado)                 ▲ pagamento.aprovado / recusado
                                      │
                                 pagamentos [pg] ──► Mercado Pago (Pix)
                                 + worker + scheduler
```

| Serviço      | Responsabilidade                                               | Banco         |
|--------------|----------------------------------------------------------------|---------------|
| `contas`     | Cadastro e login (JWT), perfil, endereços, consulta de clientes | `contas-db`   |
| `catalogo`   | Categorias, produtos, **tabela de preços com vigência**         | `catalogo-db` |
| `pedidos`    | Pedidos, status, fila da cozinha                                | `pedidos-db`  |
| `pagamentos` | Cobrança Pix (fake ou Mercado Pago), webhook, sincronização     | `pagamentos-db` |

## Rodando

Requisito: **Docker Desktop**.

```bash
cp .env.example .env        # ajuste se quiser (segredo JWT, admin, Mercado Pago)
docker compose up -d --build
```

- Swagger: http://localhost:8080/docs
- RabbitMQ: http://localhost:15672 (guest/guest)
- Admin inicial: `admin@lanchonete.dev` / `admin12345` (troque no `.env`: `ADMIN_EMAIL`, `ADMIN_PASSWORD`)

```bash
docker compose ps                          # todos "healthy"
docker compose logs -f pagamentos-worker   # ver a saga acontecendo
docker compose down -v                     # derruba tudo e apaga os bancos
```

## Autenticação

1. `POST /api/auth/registrar` (cliente) ou `POST /api/auth/login` → recebe `access_token`
2. Envie `Authorization: Bearer <token>` nas rotas protegidas
3. `POST /api/auth/refresh` renova; `POST /api/auth/logout` revoga o token **em todos os serviços**

Perfis: **cliente** (se cadastra sozinho, faz pedidos, vê só os próprios) e **admin**
(catálogo, preços, cozinha, lista de clientes).

O token é um JWT HS256 emitido pelo `contas` e **validado localmente** em cada serviço com o
mesmo `JWT_SECRET` — nenhum serviço precisa chamar o `contas` para saber quem é o usuário.
Logout grava o `jti` no Redis compartilhado, que todos os serviços consultam.

## Exemplo rápido (PowerShell)

```powershell
$api = "http://localhost:8080/api"

# cadastro (já devolve o token)
$r = Invoke-RestMethod -Method Post "$api/auth/registrar" -ContentType 'application/json' -Body (@{
  nome='Maria'; email='maria@email.com'; senha='segredo123'; senha_confirmation='segredo123'; telefone='85999990000'
} | ConvertTo-Json)
$h = @{ Authorization = "Bearer $($r.access_token)" }

# endereço + pedido delivery
$end = Invoke-RestMethod -Method Post "$api/me/enderecos" -Headers $h -ContentType 'application/json' -Body (@{
  cep='60000000'; logradouro='Rua A'; numero='10'; bairro='Centro'; cidade='Fortaleza'; uf='CE' } | ConvertTo-Json)
$ped = Invoke-RestMethod -Method Post "$api/pedidos" -Headers $h -ContentType 'application/json' -Body (@{
  tipo_entrega='delivery'; endereco_id=$end.data.id; itens=@(@{ produto_id=2; quantidade=1 }) } | ConvertTo-Json -Depth 5)

# Pix (pode levar 1-2s para existir)
Start-Sleep 2; (Invoke-RestMethod "$api/pagamentos/$($ped.data.codigo)" -Headers $h).data
```


## Testes

**91 testes automatizados (PHPUnit)**, rodando isolados: SQLite em memória, sem Redis, sem RabbitMQ e sem
rede. Os serviços vizinhos são simulados com `Http::fake` e o RabbitMQ com mocks.

```powershell
.\scripts\testes.ps1             # todos os serviços (Windows)
.\scripts\testes.ps1 pedidos     # só um serviço
./scripts/testes.sh               # Linux / macOS / WSL
```

Não precisa subir o sistema antes: o script roda cada serviço num container temporário.
No GitHub, o workflow `.github/workflows/testes.yml` roda a suíte a cada push.

| Serviço      | Testes | O que cobre |
|--------------|-------:|-------------|
| `contas`     | 28 | JWT (assinatura, expiração, adulteração, `alg: none`), cadastro, login, rate limit, logout/refresh revogando token, perfil, senha, endereços e isolamento entre clientes, admin |
| `catalogo`   | 21 | Cardápio público, filtros, permissões de admin, CRUD, **preço agendado entrando em vigor na data**, histórico, migração dos preços antigos |
| `pedidos`    | 20 | Preço sempre do catálogo, snapshot, endereço via serviço de contas com o token do cliente, dono/admin, cozinha, **saga** (aprovado/recusado, mensagem duplicada, evento atrasado) |
| `pagamentos` | 22 | Geração do Pix, idempotência, falha do provedor → compensação, webhook não confirma duas vezes, **requisição real do SDK do Mercado Pago** (HTTP simulado), status, agendador |

Teste de ponta a ponta com o sistema rodando (56 verificações passando pelo gateway):
`python scripts/smoke_test.py`

## Fluxo de um pedido (saga coreografada)

1. Cliente faz `POST /api/pedidos` → `pedidos` busca **preço vigente** no `catalogo` e o **endereço** no `contas`
   (repassando o token do próprio cliente), grava snapshot e publica `pedido.criado`
2. `pagamentos-worker` gera o Pix no provedor
3. Pagamento confirmado (webhook **ou** `pagamentos-scheduler`, que confere pendentes a cada 10s)
   → publica `pagamento.aprovado`
4. `pedidos-worker` muda o pedido para `pago` → aparece em `GET /api/cozinha`
5. Admin avança: `em_preparo` → `pronto` → `entregue`. Pagamento recusado → `cancelado` (compensação)

## Tabela de preços

Preço não é uma coluna do produto: cada mudança é um registro com `inicio_vigencia`.

- `POST /api/produtos/{id}/precos` sem data → vale agora; com data futura → **agendado**, entra em vigor sozinho
- `GET /api/produtos/{id}/precos` → histórico com `situacao`: `vigente`, `agendado`, `historico`
- Só preços agendados podem ser cancelados (histórico é imutável)
- Pedidos guardam o preço do momento da compra, então reajustes não alteram pedidos antigos

## Pagamento Pix

`PAGAMENTO_GATEWAY` no `.env`:

- `fake` (padrão): Pix simulado. Confirme com
  `POST /api/webhooks/pix {"gateway_id":"fake_xxx","status":"aprovado"}` (o `gateway_id` vem em `GET /api/pagamentos/{codigo}`)
- `mercadopago`: Pix real via SDK oficial (Orders API). Com credenciais de **teste** e
  `MERCADOPAGO_TEST_PAYER_NAME=APRO`, o Mercado Pago aprova sozinho em alguns segundos.

O provedor fica atrás da interface `GatewayPix` (Strategy): para Efí, Asaas etc., basta uma nova classe.

## Decisões importantes

- **Cada serviço tem seu banco.** Sem JOIN entre serviços; referências são IDs + snapshots.
- **Preço nunca vem do cliente.** O pedido manda só IDs e quantidades.
- **Dinheiro em centavos (inteiro).**
- **Idempotência** nos consumidores (`eventos_processados`) e na criação do Pix (`X-Idempotency-Key`).
- **Remoção lógica** de produtos/categorias: pedidos e histórico de preços continuam íntegros.
- **Rate limit** no login (5/min por e-mail+IP) e no cadastro (10/min por IP).
- **Erros sempre em JSON**, sem stack trace (`APP_DEBUG=false`). Cada resposta traz `X-Request-Id`.

## Próximos passos

- Padrão **Outbox** em `pedidos` (não perder `pedido.criado` se o RabbitMQ cair)
- JWT **RS256** com chave pública (serviços não precisariam do segredo)
- `notificacoes` consumindo `pedido.pronto` → WhatsApp/e-mail; WebSocket (Reverb) para a cozinha
- Cancelamento de pedido pelo cliente enquanto aguarda pagamento
- Testes de contrato entre serviços (Pact) e cobertura de código no CI
