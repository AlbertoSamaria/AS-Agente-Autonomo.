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

## RAG
Upload via `api/upload.php`, depois processamento via `api/process_document.php`.
Formatos: PDF, DOCX, XLSX, TXT, CSV.
A extração de PDF incluída é básica; para PDFs complexos/scaneados, adicionar um parser OCR dedicado.
No chat, os documentos processados são usados como contexto apenas para o utilizador que os enviou.

## Ferramentas do chat
- Pesquisa web: activa o botão “Pesquisa web” e escreve a pergunta. Usa o serviço HTML do DuckDuckGo; o servidor precisa de acesso à Internet.
- Ditado por voz: usa o reconhecimento de voz disponibilizado pelo navegador e pode depender de ligação à Internet e das permissões do microfone.
- Ficheiros: permite enviar PDF, DOCX, XLSX, TXT e CSV (até 20 MB por ficheiro).

## Streaming
`api/stream.php` envia respostas SSE usando o servidor selecionado em `LLM_PROVIDER` (Ollama ou API compatível com OpenAI do `llama.cpp`).

## Segurança
- RBAC por permissões.
- SQL Tool é read-only.
- Web Tool aceita apenas HTTPS.
- Ferramentas sensíveis devem passar por approval workflow.
- Não expor as portas dos servidores de modelos (`11434`/`8080`) diretamente na Internet.
- `.env` não deve ser publicado.

## Próximas melhorias recomendadas
- UI administrativa para utilizadores, permissões e aprovações.
- CSRF tokens para endpoints autenticados.
- rate limiting.
- vector database dedicado quando a base crescer.
- OCR para PDFs digitalizados.
- integração de email/push.
