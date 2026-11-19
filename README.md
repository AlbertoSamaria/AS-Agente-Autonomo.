# SA — Agente Autónomo 1.3.2

Base evoluída do SA com autenticação/RBAC, planner, dependências, memória semântica, RAG, scheduler/worker, notificações, ferramentas com permissões, aprovações, ModelRouter/fallback, streaming e Project Analyzer read-only.

## Requisitos
- PHP 8.2+
- MySQL/MariaDB
- XAMPP/Apache
- cURL, fileinfo, mbstring, PDO MySQL, ZipArchive
- Ollama
- Modelo de chat: \`ollama pull qwen2.5:1.5b\`
- Modelo de embeddings: \`ollama pull nomic-embed-text\`
- Alternativamente, um servidor \`llama.cpp\` com API compatível com OpenAI

## Instalação
1. Extrair para \`C:\\xampp\\htdocs\\SA\`.
2. Importar \`database/schema.sql\`.
3. Copiar \`.env.example\` para \`.env\`.
4. Configurar DB.
5. Criar o primeiro admin:
   \`php -r "echo password_hash('TroqueEstaSenha', PASSWORD_DEFAULT), PHP_EOL;" \`
   Depois inserir o hash na tabela \`users\`.
6. Abrir \`http://localhost/SA/\`.

## Worker
No PowerShell:
\`C:\\xampp\\php\\php.exe worker\\worker.php\`

Para execução permanente no Windows, usar Task Scheduler ou um serviço dedicado.

## Servidor llama.cpp
O SA pode usar o Ollama (padrão) ou um servidor \`llama.cpp\` para o chat. Descarrega/compila o \`llama-server\` e inicia-o localmente, apontando para um modelo GGUF:

\`\`\`powershell
& "C:\\llama.cpp\\b12317\\llama-server.exe" \`
  -m "C:\\models\\qwen2.5-1.5b-instruct-q4_k_m.gguf" \`
  --alias local-model --host 127.0.0.1 --port 8080 -c 4096 \`
  --threads 4 --repeat-penalty 1.18
\`\`\`

No \`.env\`, configura \`LLM_PROVIDER=llama.cpp\`, \`LLAMACPP_BASE_URL=http://127.0.0.1:8080\` e \`LLAMACPP_MODEL=local-model\`. O parâmetro \`--alias local-model\` faz coincidir o nome enviado pelo ModelRouter com o alias anunciado pelo servidor. O serviço de embeddings continua a usar o Ollama (\`OLLAMA_EMBEDDING_MODEL\`).

Por padrão, se o fornecedor principal falhar, o ModelRouter tenta o fornecedor alternativo. Configura \`LLM_FALLBACK_ENABLED=false\` para desativar ou define \`LLM_FALLBACK_PROVIDER\` para escolher explicitamente o secundário. O streaming segue a mesma ordem, mas não repete o pedido noutro fornecedor se já tiver enviado tokens ao cliente.

## Gemini principal e Groq como fallback cloud
O SA suporta Gemini como fornecedor principal e Groq como fallback automático, tanto no pedido normal como no streaming. Para a instalação alojada na cloud, configura no ficheiro privado \`.env\`:

\`\`\`ini
LLM_PROVIDER=gemini
LLM_FALLBACK_ENABLED=true
LLM_FALLBACK_PROVIDER=groq

GEMINI_API_KEY=CHAVE_GEMINI
GEMINI_MODEL=gemini-3.8-flash

GROQ_API_KEY=CHAVE_GROQ
GROQ_MODEL=openai/gpt-oss-20b
\`\`\`

Define também os timeouts e limites de tokens conforme necessário. Nunca coloques chaves reais em \`.env.example\`, no GitHub, em capturas de ecrã ou no chat. O cliente Groq usa a API Chat Completions compatível com OpenAI. O ModelRouter tenta Groq quando Gemini falha; se o streaming já tiver enviado tokens, não reinicia noutro fornecedor para evitar respostas duplicadas. Ollama e llama.cpp continuam disponíveis para instalações locais, mas endereços \`127.0.0.1\` não representam serviços cloud acessíveis pelo InfinityFree.

## RAG
Upload via \`api/upload.php\`, depois processamento via \`api/process_document.php\`.
Formatos: PDF, DOCX, XLSX, TXT, CSV.
A extração de PDF incluída é básica; para PDFs complexos/scaneados, adicionar um parser OCR dedicado.
No chat, os documentos processados são usados como contexto apenas para o utilizador que os enviou.
As respostas baseadas nesses trechos incluem marcadores como \`[D1]\` e uma lista com o ficheiro, o número do trecho e um excerto. A extração atual não preserva números de página, por isso as referências são a trechos, não a páginas.

## Memória privada
As memórias são consultadas apenas para o utilizador a que pertencem e as novas entradas são gravadas com o respetivo \`user_id\`. Registos antigos sem proprietário não são incluídos no contexto de nenhum utilizador. Para guardar uma memória, escreve, por exemplo, \`Lembra-te que prefiro respostas curtas\`. A função requer a permissão \`memory.write\`.

O Context Manager limita o histórico enviado ao modelo; configura \`AGENT_CONTEXT_MAX_CHARS\` para ajustar o orçamento aproximado de caracteres.

Ollama e llama.cpp usam parâmetros explícitos de temperatura, top-p e penalização de repetição. Para llama.cpp, \`LLAMACPP_REPEAT_PENALTY\`, \`LLAMACPP_FREQUENCY_PENALTY\`, \`LLAMACPP_PRESENCE_PENALTY\` e \`LLAMACPP_MAX_TOKENS\` podem ser ajustados no \`.env\`. O Agent também remove linhas e frases exactamente duplicadas antes de guardar ou apresentar a resposta, preservando blocos de código e tabelas Markdown. Isto reduz ciclos visíveis, mas não substitui um modelo maior quando a capacidade do modelo 1.5B é insuficiente.

A interface inclui temas claro e escuro, com preferência guardada localmente no navegador.

## Planos e tarefas
O planner valida títulos, prioridades e dependências, rejeita ciclos e grava tarefas e dependências numa transação, associadas à conta que pediu o plano. Consultas e alterações de tarefas também são limitadas ao proprietário.

## Ferramentas do chat
- Pesquisa web: activa o botão “Pesquisa web” e escreve a pergunta. Usa o serviço HTML do DuckDuckGo; o servidor precisa de acesso à Internet.
- Perguntas sobre informação temporal (por exemplo, cargos actuais, preços, notícias, datas relativas ou versões recentes) são pesquisadas automaticamente antes da resposta. O utilizador precisa da permissão \`tools.web\`; sem ela, o agente informa que não pode verificar a informação.
- Ditado por voz: usa o reconhecimento de voz disponibilizado pelo navegador e pode depender de ligação à Internet e das permissões do microfone.
- Ficheiros: permite enviar PDF, DOCX, XLSX, TXT e CSV (até 20 MB por ficheiro).

## Streaming
O chat envia tokens ao navegador à medida que o modelo os produz, através de SSE. O ModelRouter regista fornecedor, modelo, finalidade, fallback e latência no log PHP. O endpoint requer autenticação, CSRF, validação de propriedade da conversa/documentos e limite de frequência.

## Análise de projectos
A ferramenta \`code.project.analyze\` analisa projectos existentes dentro do workspace privado do utilizador. Identifica extensões, tecnologias por manifests, estrutura e indicadores heurísticos de risco, sem modificar ou executar ficheiros. Limites: até 300 ficheiros e 5 MB de conteúdo analisado; ignora dependências vendorizadas, pastas de build e links simbólicos. A análise não substitui SAST profissional nem auditoria manual.

Para pedir a análise ao agente: \`Analisa o projecto meu-projecto no workspace\`. A ferramenta requer a permissão \`tools.files\`.

## Workspace de programação
O agente dispõe de ferramentas para criar projectos multi-ficheiro, listar e ler ficheiros, escrever alterações e validar sintaxe PHP. Os ficheiros são guardados em \`storage/workspaces/<id-do-utilizador>/\`, separados por conta e fora da raiz pública através de regras Apache.

Ferramentas disponíveis no chat:
- \`code.project.create\`: cria um projecto com até 40 ficheiros e 2 MB no total.
- \`code.workspace.list\`: lista o conteúdo do workspace.
- \`code.file.read\`: lê ficheiros até 256 KB.
- \`code.file.write\`: escreve ficheiros até 256 KB.
- \`code.php.lint\`: valida a sintaxe PHP com \`php -l\`, sem executar o ficheiro.

**Limitação de segurança desta versão:** ainda não existe execução arbitrária de PHP, Python ou JavaScript. A validação PHP é apenas sintáctica. A execução real deverá ser adicionada numa sandbox isolada (por exemplo, contentor Docker com limites de CPU, memória, tempo, rede e sistema de ficheiros), nunca através de \`eval\`, \`shell_exec\` livre ou execução directa no host.

Os projectos criados pelo agente ficam no workspace privado do utilizador e não substituem nem alteram a instalação do próprio SA.

## Segurança e operação
Aplica a migração \`database/migrations/20261113_add_request_limits.sql\` nas instalações existentes (instalações novas recebem a tabela através de \`database/schema.sql\`). Há limites de frequência para login, chat, planner e uploads. Os eventos de ferramentas e falhas do ModelRouter são enviados ao log de erros PHP; as execuções do agente continuam registadas em \`agent_runs\`.

## Segurança
- RBAC por permissões.
- SQL Tool é read-only.
- Web Tool aceita apenas HTTPS.
- Ferramentas sensíveis devem passar por approval workflow.
- Não expor as portas dos servidores de modelos (\`11434\`/\`8080\`) diretamente na Internet.
- \`.env\` não deve ser publicado.

## Próximas melhorias recomendadas
- gestão visual e edição/apagamento de memórias pelo utilizador.
- vector database dedicado quando a base crescer.
- OCR para PDFs digitalizados.
- integração de email/push.

## Testar esta versão numa instalação existente
1. Extrai o ZIP para uma pasta paralela, por exemplo \`C:\\xampp\\htdocs\\SA-1.2.0\`.
2. Copia o \`.env\` da instalação actual para a nova pasta. O \`.env\` não é distribuído no ZIP por conter credenciais.
3. Mantém a base de dados \`sa_agent\` existente. Esta versão não exige reimportar \`schema.sql\` nem executar uma migração de dados.
4. Abre \`http://localhost/SA-1.2.0/\` e inicia sessão com a conta existente.
5. Testa primeiro o chat e o streaming. Depois pede ao SA para criar um pequeno projecto, por exemplo: \`Cria uma página web simples em HTML, CSS e JavaScript chamada teste-web, com um cabeçalho e um botão.\`

Os ficheiros gerados ficam em \`SA-1.2.0\\storage\\workspaces\\<id-do-utilizador>\\\`. O workspace é privado por regras Apache e não altera os ficheiros da instalação do SA.

**Importante:** não copies a pasta \`uploads\` antiga por cima da nova sem necessidade; os documentos continuam na base de dados e os ficheiros originais devem ser preservados na instalação antiga. Para esta primeira validação, usa a pasta paralela e mantém a versão actual intacta.

## Plano de evolução e estado real
Consulta \`ROADMAP-IMPLEMENTACAO.md\` para a matriz dos 17 capítulos, funcionalidades já existentes, novas funcionalidades desta versão, limitações e sequência de implementação. Itens parciais ou não concluídos não devem ser considerados prontos para produção.

## Versionamento e restauro do workspace
O agente pode criar cópias de segurança privadas por utilizador e restaurar uma versão anterior. As ferramentas disponíveis são \`code.workspace.snapshot\` (criar), \`code.workspace.snapshots\` (listar), \`code.workspace.diff\` (comparar) e \`code.workspace.restore\` (restaurar). O diff identifica ficheiros adicionados, alterados e removidos e apresenta diferenças linha a linha quando o tamanho permite. O restauro é uma operação sensível sujeita a aprovação e cria automaticamente uma cópia do estado actual antes de substituir a pasta.

As cópias excluem ficheiros ocultos, \`.env\`, \`.git\`, dependências, uploads, storage e links simbólicos. Existem limites de 300 ficheiros e 10 MB por operação. Este mecanismo não substitui Git nem backups externos.

## Execução de operações aprovadas
A decisão de aprovação agora executa a ferramenta sensível autorizada (incluindo leitura SQL e restauro de versões), valida novamente o utilizador solicitante e as suas permissões, regista o resultado na execução do agente e impede o processamento duplicado do mesmo pedido.
