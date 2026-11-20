# Testes automatizados do SA

Os testes são executados com PHP 8.2 e não precisam de MySQL, chaves de IA,
serviços externos ou dados de produção.

## Executar localmente

Na raiz do projecto:

```powershell
C:\xampp\php\php.exe tests/run.php
```

Verificação de sintaxe de todos os ficheiros PHP:

```powershell
Get-ChildItem -Recurse -Filter *.php | Where-Object { $_.FullName -notmatch '\\vendor\\' } | ForEach-Object { & 'C:\xampp\php\php.exe' -l $_.FullName; if ($LASTEXITCODE -ne 0) { throw "Erro PHP: $($_.FullName)" } }
```

A pipeline do GitHub executa validação do Composer, lint de todos os ficheiros
PHP, testes de regressão e verificação sintáctica dos dois ficheiros JavaScript
principais.

Estes testes são uma primeira camada de regressão. Não substituem testes de
integração com MySQL, fornecedores de IA ou o ambiente de alojamento.
