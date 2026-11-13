# SA — Agente Autónomo 1.1

Base evoluída do SA com autenticação/RBAC, planner, dependências, memória semântica, RAG, scheduler/worker, notificações, ferramentas com permissões, aprovações, model router/fallback e streaming.

## Requisitos
- PHP 8.2+
- MySQL/MariaDB
- XAMPP/Apache
- cURL, fileinfo, mbstring, PDO MySQL, ZipArchive
- Ollama
- Modelo de chat: `ollama pull qwen2.5:1.5b`
- Modelo de embeddings: `ollama pull nomic-embed-text`
- Alternativamente, um servidor `llama.cpp` com API compatível com OpenAI

## Instalação
1. Extrair para `C:\xampp\htdocs\SA`.
2. Importar `database/schema.sql`.
3. Copiar `.env.example` para `.env`.
4. Configurar DB.
5. Criar o primeiro admin:
   `php -r "echo password_hash('TroqueEstaSenha', PASSWORD_DEFAULT), PHP_EOL;"`
   Depois inserir o hash na tabela `users`.
6. Abrir `http://localhost/SA/`.

## Worker
No PowerShell:
`C:\xampp\php\php.exe worker\worker.php`

Para execução permanente no Windows, usar Task Scheduler ou um serviço dedicado.

## Servidor llama.cpp
O SA pode usar o Ollama (padrão) ou um servidor `llama.cpp` para o chat. Descarrega/compila o `llama-server` e inicia-o localmente, apontando para um modelo GGUF:

```powershell
llama-server.exe -m C:\models\modelo.gguf --host 127.0.0.1 --port 8080 -c 4096
```

No `.env`, configura `LLM_PROVIDER=llama.cpp`, `LLAMACPP_BASE_URL=http://127.0.0.1:8080` e, se necessário, `LLAMACPP_MODEL=local-model`. O nome do modelo é normalmente ignorado pelo servidor local, mas é enviado no formato compatível com OpenAI. O serviço de embeddings continua a usar o Ollama (`OLLAMA_EMBEDDING_MODEL`).

Por padrão, se o fornecedor principal falhar, o ModelRouter tenta o fornecedor alternativo. Configura `LLM_FALLBACK_ENABLED=false` para desativar ou define `LLM_FALLBACK_PROVIDER` para escolher explicitamente o secundário. O streaming segue a mesma ordem, mas não repete o pedido noutro fornecedor se já tiver enviado tokens ao cliente.

## RAG
Upload via `api/upload.php`, depois processamento via `api/process_document.php`.
Formatos: PDF, DOCX, XLSX, TXT, CSV.
A extração de PDF incluída é básica; para PDFs complexos/scaneados, adicionar um parser OCR dedicado.
No chat, os documentos processados são usados como contexto apenas para o utilizador que os enviou.
As respostas baseadas nesses trechos incluem marcadores como `[D1]` e uma lista com o ficheiro, o número do trecho e um excerto. A extração atual não preserva números de página, por isso as referências são a trechos, não a páginas.

## Memória privada
As memórias são consultadas apenas para o utilizador a que pertencem e as novas entradas são gravadas com o respetivo `user_id`. Registos antigos sem proprietário não são incluídos no contexto de nenhum utilizador. Para guardar uma memória, escreve, por exemplo, `Lembra-te que prefiro respostas curtas`. A função requer a permissão `memory.write`.

O Context Manager limita o histórico enviado ao modelo; configura `AGENT_CONTEXT_MAX_CHARS` para ajustar o orçamento aproximado de caracteres.

## Planos e tarefas
O planner valida títulos, prioridades e dependências, rejeita ciclos e grava tarefas e dependências numa transação, associadas à conta que pediu o plano. Consultas e alterações de tarefas também são limitadas ao proprietário.

## Ferramentas do chat
- Pesquisa web: activa o botão “Pesquisa web” e escreve a pergunta. Usa o serviço HTML do DuckDuckGo; o servidor precisa de acesso à Internet.
- Ditado por voz: usa o reconhecimento de voz disponibilizado pelo navegador e pode depender de ligação à Internet e das permissões do microfone.
- Ficheiros: permite enviar PDF, DOCX, XLSX, TXT e CSV (até 20 MB por ficheiro).

## Streaming
O chat envia respostas SSE usando o ModelRouter e a mesma política de fallback do modo normal. O endpoint requer autenticação, CSRF, validação de propriedade da conversa/documentos e limite de frequência.

## Segurança e operação
Aplica a migração `database/migrations/20261113_add_request_limits.sql` nas instalações existentes (instalações novas recebem a tabela através de `database/schema.sql`). Há limites de frequência para login, chat, planner e uploads. Os eventos de ferramentas e falhas do ModelRouter são enviados ao log de erros PHP; as execuções do agente continuam registadas em `agent_runs`.

## Segurança
- RBAC por permissões.
- SQL Tool é read-only.
- Web Tool aceita apenas HTTPS.
- Ferramentas sensíveis devem passar por approval workflow.
- Não expor as portas dos servidores de modelos (`11434`/`8080`) diretamente na Internet.
- `.env` não deve ser publicado.

## Próximas melhorias recomendadas
- gestão visual e edição/apagamento de memórias pelo utilizador.
- vector database dedicado quando a base crescer.
- OCR para PDFs digitalizados.
- integração de email/push.
