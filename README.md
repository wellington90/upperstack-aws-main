# Upperstack AWS Demo (EC2 + PHP + Nginx)

Aplicação didática para ensinar deploy de uma stack simples em **uma EC2**, com front e backend PHP no mesmo servidor, demonstrando:

1. Conexão com **RDS** via `.env`.
2. Leitura de variáveis de ambiente da instância EC2.
3. Leitura de segredo no **AWS Secrets Manager** e conexão no RDS via secret.
4. Upload de arquivo para **S3**.
5. Chamada de **Lambda** e envio de mensagem para **SQS**.
6. Consulta de fila processada para retorno no front.
7. Coleta de metadados da instância EC2 e teste didático de Auto Scaling por estresse de CPU.

## Arquitetura da v1

- **EC2**: hospeda Nginx + PHP-FPM + app (front + backend).
- **RDS**: banco MySQL/PostgreSQL (exemplo usa DSN de MySQL).
- **Secrets Manager**: guarda segredo de aplicação.
- **S3**: recebe upload de arquivos.
- **Lambda + SQS**:
  - Front envia mensagem para backend.
  - Backend chama Lambda e publica em uma fila SQS.
  - Outra Lambda processa mensagem e publica em fila de saída.
  - Front consulta a fila processada via endpoint.

## Estrutura

- `public/index.php`: tela web com menus didáticos.
- `public/api.php`: endpoints para cada demonstração.
- `src/Config.php`: leitura de `.env` + variáveis do ambiente.
- `src/AwsDemoService.php`: integrações com AWS SDK.
- `nginx/default.conf`: exemplo de virtual host Nginx.
- `.env.example`: variáveis necessárias.
- `database/schema.sql`: script SQL para tabela `users` (CRUD didático no RDS).
- `lambda/order-intake/index.mjs`: lambda para entrada de pedidos e publicação na fila.
- `lambda/order-processor/index.mjs`: lambda para processamento e retorno na fila de saída.
- `docs/lambda-use-case.md`: explicação do cenário real usado na aula.

## Pré-requisitos

- Ubuntu 22.04+ na EC2
- PHP 8.2+
- Composer
- Nginx
- Permissões IAM na role da EC2 para:
  - `secretsmanager:GetSecretValue`
  - `s3:PutObject`
  - `lambda:InvokeFunction`
  - `sqs:SendMessage`
  - `sqs:ReceiveMessage`

## Deploy rápido na EC2

```bash
sudo apt update
sudo apt install -y nginx software-properties-common ca-certificates lsb-release unzip

# Ubuntu 22.04 normalmente já possui PHP 8.1/8.2 nos repositórios.
# Se você precisa de PHP 8.2+ e o apt não encontrar (erro "Unable to locate package php8.2-*"),
# habilite o PPA do Ondřej Surý:
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update

# Prefira pacotes sem fixar a versão (funciona em Ubuntu 22.04/24.04):
sudo apt install -y php-fpm php-cli php-curl php-mbstring php-xml php-mysql

cd /var/www
sudo git clone <repo-url> upperstack-aws
cd upperstack-aws

sudo php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
composer install --no-dev

cp .env.example .env
# edite o .env com valores reais

sudo cp nginx/default.conf /etc/nginx/sites-available/upperstack-aws.conf
sudo ln -s /etc/nginx/sites-available/upperstack-aws.conf /etc/nginx/sites-enabled/upperstack-aws.conf
sudo rm -f /etc/nginx/sites-enabled/default
# Se seu PHP-FPM for 8.4 (ou outra versão), ajuste o socket em nginx/default.conf
# de /run/php/php8.2-fpm.sock para a versão instalada.

PHP_FPM_SERVICE=$(systemctl list-units --type=service --all | awk '/php.*-fpm.service/ {print $1; exit}')
if [ -z "$PHP_FPM_SERVICE" ]; then
  echo "Nenhum serviço php-fpm encontrado. Verifique a instalação do PHP."
  exit 1
fi

sudo chown -R www-data:www-data /var/www/upperstack-aws
sudo nginx -t
sudo systemctl restart "$PHP_FPM_SERVICE"
sudo systemctl restart nginx
```

Acesse o IP público da EC2 e use os menus.

## SQL para subir no RDS

Após configurar as credenciais no `.env`, execute:

```bash
mysql -h <endpoint-rds> -u <usuario> -p <nome_do_banco> < database/schema.sql
```

Ou copie o conteúdo de `database/schema.sql` para o Query Editor.

## Secret para conectar no RDS (boa prática)

No menu **AWS Secrets Manager**, o botão **Conectar RDS via secret** usa o `AWS_SECRET_ID` para buscar as credenciais e abrir conexão PDO sem depender de `RDS_USER` e `RDS_PASSWORD` no `.env`.

Exemplo de JSON no SecretString:

```json
{
  "engine": "mysql",
  "host": "meu-rds.cluster-xxxx.us-east-1.rds.amazonaws.com",
  "port": 3306,
  "dbname": "demo",
  "username": "admin",
  "password": "senha-super-secreta",
  "charset": "utf8mb4"
}
```

Também é aceito `dsn` pronto no secret:

```json
{
  "dsn": "mysql:host=...;port=3306;dbname=demo;charset=utf8mb4",
  "username": "admin",
  "password": "senha-super-secreta"
}
```

## Lambdas de exemplo (caso real didático)

Para simular uma operação de e-commerce:

- **order-intake**: recebe pedido, registra evento na fila de entrada e aciona processamento.
- **order-processor**: calcula prioridade do pedido e publica resultado na fila de saída.

Arquivos:
- `lambda/order-intake/index.mjs`
- `lambda/order-processor/index.mjs`
- documentação: `docs/lambda-use-case.md`

## Conteúdo da mensagem enviada para SQS/Lambda

Você pode enviar **qualquer texto** no campo da tela, por exemplo:

- `Pedido #123 confirmado`
- `Cliente Maria solicitou prioridade`

Para uma didática mais real, prefira enviar um JSON em formato de pedido:

```json
{
  "order_id": "ORD-1001",
  "customer_name": "Maria Souza",
  "customer_email": "maria@cliente.com",
  "total_amount": 799.90,
  "items_count": 3
}
```

As Lambdas em `lambda/order-intake` e `lambda/order-processor` já estão preparadas para esse cenário.

## Página inicial: Metadata + teste de Auto Scaling

Na seção **Início (EC2 Metadata)** a aplicação mostra:

- `instance_id`
- `private_ip`
- `availability_zone`
- `region`
- horário da coleta

Também existe o botão **Testar Auto Scaling (CPU 100%)**, que inicia processos em background para elevar CPU e facilitar demonstração de scale-out (ASG + ALB).

> Atenção: use esse botão apenas em ambiente de laboratório controlado e com limites de custo.

## Logo da página via S3 (branding Upperstack)

- Faça upload de uma imagem na seção **Upload para S3** da aplicação.
- O backend salva no prefixo `branding/` do bucket definido em `S3_BUCKET`.
- O frontend busca automaticamente o logo mais recente no S3 e exibe no menu lateral.

## Exemplo de variáveis na EC2 (sem .env)

Você pode exportar variáveis no sistema para demonstrar outro método de configuração:

```bash
export APP_ENV=prod
export AWS_REGION=us-east-1
export EC2_INSTANCE_ID=i-xxxxxxxxxxxx
```

## Próximos passos sugeridos para aulas

- Adicionar autenticação por IAM Role + STS e remover credenciais locais.
- Persistir logs estruturados no CloudWatch.
- Implementar endpoint de healthcheck para ALB.
- Separar front em S3 + CloudFront na v2.

## Deploy automatizado com CodeBuild e CodeDeploy

O repositório também contém os arquivos necessários para uma demonstração de
pipeline até a EC2:

- `buildspec.yml`: lê parâmetros do Systems Manager Parameter Store, gera o
  `.env` sem imprimir os valores no log e publica o projeto como artefato.
- `appspec.yml`: copia o artefato para `/var/www/upperstack-aws` e executa os
  hooks do CodeDeploy.
- `script/beforeinstall.sh`: para Nginx e PHP-FPM antes da instalação.
- `script/afterinstall.sh`: instala as dependências Composer e reinicia os serviços.

Crie os parâmetros usados pelo `buildspec.yml` sob o prefixo
`/upperstack-aws/`. Exemplo:

```bash
aws ssm put-parameter --name /upperstack-aws/APP_ENV --type String --value prod
aws ssm put-parameter --name /upperstack-aws/AWS_REGION --type String --value us-east-1
aws ssm put-parameter --name /upperstack-aws/RDS_PASSWORD --type SecureString --value 'senha-segura'
```

Repita o comando para as demais chaves declaradas na seção
`env.parameter-store` do `buildspec.yml`. A role de serviço do CodeBuild precisa
de `ssm:GetParameters` e, para parâmetros `SecureString` com chave gerenciada
pelo cliente, de `kms:Decrypt`.

Na EC2, instale e configure o agente do CodeDeploy e associe uma instance role
que permita ao agente baixar o artefato. O destino deve possuir previamente
Nginx, PHP-FPM e Composer, conforme os pré-requisitos deste README. O arquivo
`.env` gerado faz parte apenas do artefato do pipeline e continua ignorado pelo
Git.
