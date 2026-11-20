# SA 1.3.7 — Confirmação de e-mail e recuperação de conta

## Novidades
- Confirmação de endereço de e-mail para novas contas antes do primeiro login.
- Reenvio de confirmação com resposta que não revela se um e-mail está registado.
- Recuperação de palavra-passe por link temporário, de utilização única e válido durante uma hora.
- Tokens aleatórios de 256 bits armazenados apenas como hashes SHA-256.
- Contas existentes marcadas como confirmadas na migração para preservar o acesso.
- Limites de tentativas para registo, reenvio, recuperação e redefinição.
- Serviço de envio de e-mail configurável com Resend API ou `mail()` do PHP.

## Configuração necessária
- Definir `APP_BASE_URL`, `MAIL_PROVIDER`, `MAIL_FROM` e `RESEND_API_KEY` no ficheiro privado `.env`.
- Validar o domínio remetente no Resend.
- Aplicar uma única vez a migração `database/migrations/20261120_account_email_verification_password_reset.sql` numa instalação existente.

## Segurança
- O formulário público continua a criar exclusivamente contas `viewer`.
- Os tokens são invalidados após utilização e os links têm expiração.
- A recuperação não confirma se um endereço de e-mail existe.
- O envio de mensagens depende da configuração do fornecedor e da disponibilidade de cURL ou da função PHP `mail()`.
