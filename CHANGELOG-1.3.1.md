# SA 1.3.1 — Workspace Versioning

## Adicionado
- Snapshots privados por utilizador para pastas do workspace ou workspace completo.
- Listagem de versões guardadas.
- Restauro de versão com aprovação obrigatória.
- Salvaguarda automática do estado actual antes do restauro.
- Exclusão de ficheiros ocultos, `.env`, `.git`, dependências, uploads, storage e links simbólicos.
- Limites por operação: 300 ficheiros e 10 MB; ficheiros individuais até 256 KB.

## Segurança
- Os snapshots ficam fora da raiz pública, em `storage/workspace_snapshots/<user_id>`, protegidos por `.htaccess` e permissões restritas.
- Nenhuma tabela SQL ou dado existente foi alterado.
- O restauro nunca é executado sem intenção explícita e aprovação.

## Limitações
- Ainda não há diff visual, exportação ZIP ou integração Git.
- O mecanismo de snapshots não substitui backups externos.

## Correcção adicional
- A API de aprovações passou a executar a ferramenta sensível aprovada, revalidar o utilizador e permissões, evitar aprovação duplicada e actualizar o resultado da execução.
- A mesma correcção activa a execução de consultas SQL de leitura que já aguardavam aprovação.
