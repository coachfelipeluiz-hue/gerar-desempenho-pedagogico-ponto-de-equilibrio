# Como publicar este projeto no GitHub

## Opção recomendada: GitHub Desktop

1. Baixe e extraia o ZIP em uma pasta do computador.
2. Instale e abra o GitHub Desktop: https://desktop.github.com/
3. Entre com a conta GitHub que tem acesso ao repositório.
4. Use **File > Clone repository** e selecione `coachfelipeluiz-hue/gerar-desempenho-pedagogico-ponto-de-equilibrio`.
5. Copie os arquivos e pastas extraídos para dentro da pasta local clonada, substituindo somente arquivos de mesmo nome se necessário. Preserve o `README.md` existente ou use o README deste pacote se preferir.
6. No GitHub Desktop, confira a lista de arquivos. Não inclua planilhas, CSVs reais, senhas ou configurações locais.
7. Escreva uma mensagem como `chore: adicionar estrutura inicial PHP e MySQL` e clique em **Commit to main**.
8. Clique em **Push origin** para enviar os arquivos ao GitHub.

Se preferir usar o terminal, entre na pasta clonada e execute:

```bash
git add .
git commit -m "chore: adicionar estrutura inicial PHP e MySQL"
git push origin main
```

## Segurança

- Este repositório é público. Não envie planilhas reais de contratos, dados pessoais de aprendizes/colaboradores, credenciais ou arquivos `.env`.
- O arquivo `config/database.php` é ignorado pelo Git. Use `config/database.example.php` como modelo.
- O sistema é um MVP de desenvolvimento; os importadores, permissões por unidade, relatórios e simulador precisam ser implementados e testados antes de uso operacional.
