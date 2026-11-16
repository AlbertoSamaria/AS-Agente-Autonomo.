# SA 1.2.0 — Alterações desta entrega

## Incluído
- Streaming real: os tokens são encaminhados para o navegador assim que chegam do fornecedor.
- ModelRouter: registo explícito de sucesso/falha, fornecedor, modelo, finalidade, fallback e latência.
- Routing de pedidos de programação para a finalidade `code`, permitindo registar modelos especializados na tabela `model_registry`.
- Coding workspace inicial: criar projectos multi-ficheiro, listar, ler e escrever ficheiros em área privada por utilizador.
- Validação de sintaxe PHP com `php -l`, sem executar o código.
- Protecção contra traversal, caminhos absolutos, ficheiros `.env`, links simbólicos e nomes reservados do Windows.
- Ferramentas de programação integradas no ToolRegistry e nas instruções do agente.
- Auditoria de ferramentas alinhada com a estrutura real da tabela `audit_logs`.
- Removida a segunda declaração redundante de `audit_logs` do schema.
- Protecção Apache para `storage` e `uploads`.
- Endpoint de diagnóstico limitado ao administrador e sem expor detalhes internos de erros MySQL.
- Documentação de instalação de teste em pasta paralela.

## Limitações conhecidas
- Ainda não existe execução arbitrária de PHP, Python ou JavaScript. A execução real requer sandbox isolada.
- A validação `code.php.lint` apenas verifica sintaxe PHP.
- A geração de projectos depende da capacidade do modelo configurado; modelos pequenos podem precisar de pedidos divididos em etapas.
- Não foi possível executar testes de integração contra a base de dados do utilizador ou os servidores Ollama/llama.cpp locais neste ambiente.
