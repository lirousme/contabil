# Contábil

Aplicação PHP simples para cadastro, edição, listagem e exclusão de empresas usando MySQL/MariaDB.

## Configuração

1. Copie `.env.example` para `.env` e preencha as credenciais do banco.
2. A aplicação cria automaticamente a tabela `empresas` ao abrir a página; se preferir, execute manualmente a migração em `database/migrations/001_create_empresas.sql`.
3. Inicie a aplicação:

```bash
php -S localhost:8000 -t public
```

Todas as páginas usam Tailwind CSS com tema escuro habilitado por padrão.
