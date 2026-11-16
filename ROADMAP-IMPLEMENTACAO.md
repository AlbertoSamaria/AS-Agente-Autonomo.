# SA — Matriz de implementação do plano de agente autónomo

Esta versão parte do `SA-1.2.1-TESTE.zip`. O projecto já continha autenticação/RBAC, planner e tarefas com dependências, memória privada, RAG, worker/scheduler, notificações, aprovações, ModelRouter Ollama/llama.cpp, streaming SSE e workspace privado com criação/leitura/escrita de ficheiros e lint PHP.

## Implementado nesta versão

- **Project Analyzer inicial:** ferramenta `code.project.analyze`, read-only, limitada ao workspace do utilizador. Enumera ficheiros, extensões, tecnologias/frameworks por manifests e indicadores heurísticos (`eval`, execução de comandos, padrões SQL e TODO/FIXME). Tem limites de ficheiros/tamanho e ignora pastas de dependências e links simbólicos.
- **Workspace Versioning:** ferramentas `code.workspace.snapshot`, `code.workspace.snapshots`, `code.workspace.diff` e `code.workspace.restore`; cópias privadas por utilizador, exclusão de segredos/dependências/links simbólicos, limites de 300 ficheiros e 10 MB, salvaguarda automática antes do restauro e aprovação obrigatória para restaurar.
- **Integração com o agente:** registo das ferramentas, permissão `tools.files`, descrição no prompt e validação de intenção explícita.
- **Preservação:** não altera o schema SQL, não executa migrações e não altera dados existentes.

## Matriz dos 17 capítulos

| Capítulo | Estado nesta versão | Observação |
|---|---|---|
| 1. Objectivo principal | Parcial | Fundação de agente e workspace existentes; autonomia de ponta a ponta ainda não concluída. |
| 2. Raciocínio e inteligência | Parcial | Planner, contexto e auto-verificação existentes; raciocínio multi-etapa persistente e retoma robusta por concluir. |
| 3. Coding Agent | Parcial | Criação/leitura/escrita de projectos e lint PHP; refactor multi-ficheiro avançado, testes multi-linguagem e dependências por concluir. |
| 4. Web/frameworks | Parcial | Geração multi-ficheiro; matrizes de frameworks e testes reais por stack por concluir. |
| 5. Execução real/sandbox | Não concluído | Não executar código arbitrário no processo PHP/Windows. Requer runner isolado (Docker/VM), limites e política de rede. |
| 6. Project Analyzer | Implementação inicial | Análise estática heurística read-only; SAST/AST profundo e auditoria de dependências por concluir. |
| 7. Workspace Manager | Parcial (melhorado) | Workspace privado, criação/leitura/escrita, snapshots, diff textual e restauro com aprovação; diff visual integrado na UI, exportação ZIP e Git por concluir. |
| 8. Multi-agent | Não concluído | Não existe ainda orquestrador de agentes especializados validado. |
| 9. Memória avançada | Parcial | Memória privada, embeddings e RAG existentes; vector store dedicado, UI completa de gestão e políticas de retenção por concluir. |
| 10. Modelos | Parcial | Ollama/llama.cpp, fallback, registry e logs; APIs externas e routing calibrado por benchmark por concluir. |
| 11. Pesquisa/documentação | Parcial | WebTool existente; fontes robustas, documentação oficial versionada e citações consistentes por concluir. |
| 12. Segurança/autonomia | Parcial (melhorado) | RBAC, aprovação com execução efectiva, rate limits e logs; níveis de autonomia formais e sandbox por concluir. |
| 13. Interface | Parcial | Chat responsivo, SSE, histórico, upload, temas e tarefas existentes; painel completo de execução e diff por concluir. |
| 14. Testes/qualidade | Parcial | Health endpoint e lint PHP; suites automatizadas, integração, segurança e carga por concluir. |
| 15. Infraestrutura | Parcial | PHP/MySQL/XAMPP e serviços locais; deploy cloud e observabilidade por concluir. |
| 16. Ordem de implementação | Em curso | Esta entrega acrescenta análise de projecto à base 1.2.1; não representa conclusão das cinco fases. |
| 17. Regras Copilot | Documentado | Regras de preservação e validação mantidas; ver instruções no README e esta matriz. |

## Segurança e limites

- A análise de projecto é **heurística**, não certifica ausência de vulnerabilidades.
- `code.php.lint` só valida sintaxe PHP; não executa código.
- O workspace não é a pasta de instalação do SA e não deve ser usado como sandbox de execução.
- Não foi adicionada execução arbitrária de PHP, Python, Node.js ou comandos do sistema. Isso exige isolamento real, limites de CPU/RAM/tempo, rede desactivada por defeito, filesystem efémero e auditoria.
- Não foi alterada a base de dados nem incluído `.env` com credenciais.

## Próxima sequência técnica

1. Fechar testes de integração e estabilidade do ModelRouter/streaming no ambiente XAMPP real.
2. Integrar o diff na UI, acrescentar exportação ZIP compatível com o PHP instalado e integração Git; snapshots, diff textual e rollback básico já estão disponíveis.
3. Implementar runner Docker/VM com imagem mínima por linguagem e API de jobs, mantendo o runner fora do Apache.
4. Só depois ligar o Testing/Debugging Agent ao runner.
5. Implementar orquestrador multi-agent com limites de custo, timeouts, tracing e aprovação humana.
6. Completar gestão visual de memórias, pesquisa com fontes e monitorização.

Nenhum item marcado como parcial ou não concluído deve ser apresentado como funcionalidade pronta para produção.
