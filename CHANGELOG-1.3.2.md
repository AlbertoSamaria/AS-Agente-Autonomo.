# SA 1.3.2 — Workspace Diff

## Adicionado
- Ferramenta `code.workspace.diff` para comparar uma pasta actual com um snapshot.
- Detecção de ficheiros adicionados, alterados e removidos.
- Diff linha a linha com limites de tamanho e truncagem controlada.
- Integração no ToolRegistry, prompt do agente e validação de intenção explícita.

## Testes
- Comparação de snapshot com ficheiro alterado e ficheiro novo: aprovada.
- Resumo de alterações (added/modified/deleted): aprovado.
- Sintaxe PHP de todos os ficheiros: verificada na entrega.

## Limitações
- O diff ainda é apresentado como resultado da ferramenta, sem interface visual dedicada.
- Exportação ZIP e integração Git continuam por implementar.
