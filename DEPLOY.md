# Deploy — Fase 1 (auth + companies)

## Passos após o commit + deploy no Easypanel

No console do Easypanel (serviço backend), rodar **uma vez**:

```bash
php artisan migrate --force
php artisan db:seed --force
```

> `storage:link` roda automaticamente no boot do container (entrypoint.sh).

- `migrate` cria as tabelas `companies` e as colunas `company_id`/`role` em `users`.
- `db:seed` cria o super admin `admin@traxiinvest.com` (senha padrão `password`;
  defina `SEED_ADMIN_PASSWORD` na aba Environment antes de rodar para usar outra)
  e duas empresas de exemplo. O seeder é idempotente.
- Confirme que `APP_URL` na aba Environment aponta para
  `https://api-traxii-backend.rmmcki.easypanel.host` — o link público do logo usa
  essa URL.

## Volume persistente (Easypanel → Armazenamento)

Uploads precisam de **dois volumes** no serviço backend para sobreviver a
redeploys:

| Nome | Caminho de montagem | Conteúdo |
| --- | --- | --- |
| `storage-public` | `/var/www/html/storage/app/public` | Logotipos (servidos publicamente) |
| `storage-private` | `/var/www/html/storage/app/private` | Documentos/selfies das aberturas de conta (disco `local`, nunca públicos) |

O entrypoint recria o symlink `public/storage` e ajusta permissões
(`www-data`) a cada boot. Não monte o volume em `/var/www/html/storage`
inteiro (cobriria `framework/` e quebraria o boot).

## Endpoints desta fase

| Método | Rota | Acesso |
| --- | --- | --- |
| POST | `/api/auth/login` | público (throttle 10/min) |
| POST | `/api/auth/logout` | autenticado |
| GET | `/api/auth/me` | autenticado |
| GET | `/api/public/theme?domain=` | público (tema whitelabel) |
| GET/POST | `/api/companies` | super_admin |
| GET/PUT/DELETE | `/api/companies/{id}` | super_admin |

Upload de logo: multipart `logo` (imagem, máx. 2 MB) no POST; update usa POST com
`_method=PUT`. `remove_logo=1` remove o logo atual.

## E-mails ao proponente (prontos, desativados)

Aprovação, reprovação (mensagem padrão — o motivo real nunca sai do gestor) e
pendência (mensagem do operador + link de resolução). Para ativar, na aba
Environment:

```env
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=nao-responda@dominio.com
MAIL_FROM_NAME="Percapital"
MAIL_NOTIFICATIONS_ENABLED=true
```

Sem `MAIL_NOTIFICATIONS_ENABLED=true` nada é enviado (apenas log). Falha de
SMTP nunca bloqueia a operação de aprovação/reprovação/pendência.

O link de resolução da pendência usa o domínio cadastrado da empresa
(`https://{dominio}/pendencia/{uuid}?t=...`) e também é exibido no gestor
na criação da pendência, para envio manual enquanto o e-mail está desativado.

> Migration desta etapa: `php artisan migrate --force`
> (tabela `account_opening_pendencies`).

## Papéis (coluna `users.role`)

`super_admin` (global, sem company_id) · `company_admin` · `company_operator`
(escopados por `company_id`). Middleware: `role:super_admin` em rotas de gestão.
