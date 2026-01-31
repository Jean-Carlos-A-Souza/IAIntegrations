# IAFuture (IAF) - Backend SaaS Multi-tenant

Base de backend Laravel para a plataforma IAFuture (IAF). Este projeto foi estruturado para isolamento total por tenant usando **schema por tenant** no PostgreSQL, autenticação com Sanctum, jobs em Redis e integração com OpenAI.

## Requisitos
- Docker + Docker Compose
- PHP 8.2+
- PostgreSQL 16 com extensão **pgvector**
- Redis 7

## Setup rápido
1. Suba os containers:
   ```bash
   docker-compose up -d --build
   ```
2. Instale dependências (no container):
   ```bash
   docker-compose run --rm app composer install
   ```
3. Gere a chave da aplicação:
   ```bash
   docker-compose run --rm app php artisan key:generate
   ```
4. Rode migrações do schema **public**:
   ```bash
   docker-compose run --rm app php artisan migrate
   ```
5. Crie o schema do tenant (exemplo `tenant_123`) e rode as migrações:
   ```bash
   docker exec -it iafuture_postgres psql -U iafuture -d iafuture -c "CREATE SCHEMA IF NOT EXISTS tenant_123;"
   docker-compose run --rm app php artisan migrate --path=database/migrations/tenant
   ```

> **Importante:** o middleware `ResolveTenant` ajusta o `search_path` para o schema do tenant e mantém o `public` na pilha.

## Fluxo multi-tenant
- Resolver tenant por **header**, **subdomínio** ou **token** (`TENANT_RESOLUTION_MODE`).
- `ResolveTenant` aplica `SET search_path`.
- `EnsureTenantIsolation` bloqueia chamadas sem tenant resolvido.

## Principais serviços
- **OpenAIService**: chat + embeddings.
- **RAGService**: chunking, normalização, busca vetorial.
- **TokenCounter**: contabilização mensal e enforcement de plano.
- **TenantResolver**: resolução e aplicação de schema.

## Endpoints essenciais
- Auth: `/api/auth/login`, `/api/auth/logout`, `/api/auth/me`
- Tenant: `/api/tenant`, `/api/tenant/users`
- Knowledge: `/api/knowledge/documents`
- Chat: `/api/chat/ask`, `/api/chat/{id}/message`
- AI Settings: `/api/ai/settings`
- Usage: `/api/usage/monthly`, `/api/analytics/top-questions`
- API Keys: `/api/api-keys`, `/api/api-keys/{id}/rotate`

## Knowledge Base (documentos)
### Rotas
- `POST /api/knowledge/documents` (multipart/form-data)
- `GET /api/knowledge/documents`
- `GET /api/knowledge/documents/{id}`
- `DELETE /api/knowledge/documents/{id}`

### Exemplo de upload
```bash
curl -X POST "http://localhost:8000/api/knowledge/documents" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "X-Tenant-ID: <TENANT_ID>" \
  -F "file=@/path/to/notes.txt" \
  -F "title=Notas" \
  -F "tags[]=manual"
```

### Exemplo de listagem
```bash
curl -X GET "http://localhost:8000/api/knowledge/documents" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "X-Tenant-ID: <TENANT_ID>"
```

### Exemplo de detalhe
```bash
curl -X GET "http://localhost:8000/api/knowledge/documents/{id}" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "X-Tenant-ID: <TENANT_ID>"
```

### Exemplo de remoção
```bash
curl -X DELETE "http://localhost:8000/api/knowledge/documents/{id}" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "X-Tenant-ID: <TENANT_ID>"
```

## Observações de segurança
- Nenhuma query deve rodar sem `TenantContext` ativo.
- Armazenamento de documentos isolado por schema e bucket.
- Tokens contabilizados por request com limite mensal por plano.

## Próximos passos recomendados
- Adicionar parser de PDF/DOCX (ex: `smalot/pdfparser`, `phpoffice/phpword`).
- Adicionar testes automatizados para isolamento multi-tenant.
- Implementar verificação de API Keys no middleware.
