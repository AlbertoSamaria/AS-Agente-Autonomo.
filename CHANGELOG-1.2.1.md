# SA 1.2.1

## Correcção de listas numeradas

- Corrigida a renumeração de listas no `Agent.php`.
- Linhas vazias já não reiniciam automaticamente a numeração.
- Repetições consecutivas como `1.`, `1.`, `1.` passam para `1.`, `2.`, `3.`.
- Blocos de código Markdown continuam intocados.
- Tabelas Markdown continuam intocadas.
- O frontend normaliza a resposta final após o streaming, evitando que a numeração final fique presa ao formato enviado pelo modelo.
- Mantida a estrutura e a base de dados existentes.
