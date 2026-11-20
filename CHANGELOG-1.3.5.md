# SA 1.3.5

## Orquestração multi-etapa de ferramentas

- Adiciona o serviço `Orchestrator` para encadear até quatro ferramentas numa única execução.
- Cada ferramenta adicional volta a validar a intenção explícita do utilizador e as permissões RBAC.
- Regista cada operação na auditoria e mantém evidências dos resultados para a resposta final.
- Detecta chamadas repetidas e interrompe cadeias sem progresso.
- Aplica limite ao tamanho dos resultados enviados novamente ao modelo.
- Operações sensíveis continuam a exigir aprovação humana; a cadeia fica suspensa quando a aprovação é necessária.
- Preserva o workspace privado e não introduz execução arbitrária de código.
