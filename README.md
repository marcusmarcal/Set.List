# Sistema de Gerenciamento de Músicas

Este é um sistema de gerenciamento de músicas simples desenvolvido em PHP, com o objetivo de gerenciar uma lista de músicas com funcionalidades para adicionar, editar, excluir e visualizar músicas.

## Funcionalidades

- **Listagem de Músicas**: Visualize a lista completa de músicas em uma tabela organizada.
- **Adicionar Música**: Adicione novas músicas à lista através de um formulário simples.
- **Editar Música**: Modifique as informações das músicas existentes.
- **Excluir Música**: Remova músicas da lista.
- **Ordenação**: Ordene a lista de músicas por título, artista ou índice.
- **Arrastar para Ordenar**: Reordene a lista de músicas arrastando-as na tabela.
- **Impressão**: Visualize a lista de músicas em um formato pronto para impressão.

## Estrutura do Projeto

O projeto consiste nos seguintes arquivos principais:

- **index.php**: Página principal que exibe a lista de músicas.
- **add.php**: Página para adicionar novas músicas.
- **edit.php**: Página para editar músicas existentes.
- **delete.php**: Script para excluir músicas.
- **print_songs.php**: Página para visualizar a lista de músicas em formato de impressão.
- **songs.json**: Arquivo JSON que armazena os dados das músicas.

## Pré-requisitos

Para executar este sistema, você precisará de:

- Um servidor web com suporte a PHP (como Apache ou Nginx).
- PHP 7.0 ou superior.
- Acesso a um navegador para visualizar as páginas.

## Configuração

1. Clone este repositório ou faça o download dos arquivos para o seu servidor web.
2. Certifique-se de que o arquivo `songs.json` tem permissões de leitura e escrita para que o PHP possa modificar seus dados.
3. Acesse `index.php` através do seu navegador para começar a usar o sistema.

## Uso

### Adicionar Música

1. Na página principal, clique no botão "Adicionar Música".
2. Preencha o formulário com o título e o artista da música.
3. Clique em "Salvar" para adicionar a música à lista.

### Editar Música

1. Na lista de músicas, clique no botão "Editar" ao lado da música que deseja modificar.
2. Atualize as informações conforme necessário e clique em "Salvar".

### Excluir Música

1. Na lista de músicas, clique no ícone de lixeira ao lado da música que deseja remover.
2. Confirme a exclusão quando solicitado.

### Reordenar Músicas

1. Clique e segure na linha da música que deseja mover.
2. Arraste a linha para a posição desejada e solte.

### Imprimir Lista

1. Acesse `print_songs.php` para visualizar a lista de músicas em formato de impressão.
2. Utilize as opções do navegador para imprimir a página.

## Licença

Este projeto é distribuído sob a licença MIT. Consulte o arquivo `LICENSE` para obter mais detalhes.

## Contribuição

Contribuições são bem-vindas! Sinta-se à vontade para abrir issues ou pull requests para melhorias e correções.

## Autor

Desenvolvido por [Seu Nome]. 

Entre em contato em [seu-email@example.com] para dúvidas ou suporte.
