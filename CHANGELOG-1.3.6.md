# SA 1.3.6 — Registo de contas

## Novidades
- Adicionada opção para qualquer visitante criar uma conta própria a partir do ecrã de login.
- Registo com nome, email, palavra-passe e confirmação.
- Validação no servidor, prevenção de emails duplicados e limite de tentativas por IP.
- Palavras-passe protegidas com `password_hash()`.
- Contas criadas com o perfil `viewer`; o registo público não permite atribuir privilégios administrativos.
- Início automático de sessão após o registo e regeneração do identificador de sessão após autenticação.

## Segurança e limitações
- A verificação de email ainda não está activa.
- A tabela `request_limits` e as tabelas de utilizadores devem existir, conforme o schema actual.
- Requer teste funcional na instalação PHP/MySQL antes de publicar em produção.
