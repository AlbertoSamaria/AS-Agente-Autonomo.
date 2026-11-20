# SA Plan Worker

O worker executa etapas persistentes elegíveis dos planos, respeitando dependências e limite de tentativas.

## Execução manual (XAMPP / Windows PowerShell)

Na raiz do projecto:

```powershell
C:\xampp\php\php.exe worker\plan_worker.php 3
```

O argumento opcional limita o número de etapas processadas por execução (1–10; padrão 3).

## Agendamento

Configura o Agendador de Tarefas do Windows para executar periodicamente:

- Programa: `C:\xampp\php\php.exe`
- Argumentos: `C:\xampp\htdocs\SA\worker\plan_worker.php 3`
- Iniciar em: `C:\xampp\htdocs\SA`

Em Linux, usa cron com o PHP CLI. O worker não disponibiliza endpoint HTTP.

## Segurança e limites

- Só processa planos pendentes/em execução e etapas cujas dependências estejam concluídas.
- A reivindicação da etapa é transaccional para reduzir execuções concorrentes.
- Recupera etapas presas em execução há mais de 15 minutos e respeita o máximo de tentativas.
- Usa ModelRouter e o fallback configurado.
- O modelo apenas produz texto. Não recebe ferramentas, não executa comandos nem realiza operações externas.
- Para etapas que exigem alterações reais, o resultado indica que é necessária uma acção segura/autorizada.
- A base de dados deve ter a migração da Fase 2 aplicada antes de iniciar o worker.

O worker processa no máximo 10 etapas por chamada para evitar execuções longas sem controlo.
