# Traxiinvest Backend API

Este é o repositório do backend do projeto **Traxiinvest**, desenvolvido em **Laravel 13** com autenticação via **Laravel Sanctum**. O ambiente de produção é containerizado com Docker e hospedado na VPS Hostinger sob o gerenciamento do **Easypanel**.

---

## 🛠️ Stack Tecnológica

* **Framework:** Laravel 13
* **Versão PHP:** 8.4
* **Banco de Dados:** MySQL
* **Autenticação:** Laravel Sanctum (Tokens de API)
* **Web Server (Produção):** Nginx + PHP-FPM
* **Containerização:** Docker (Alpine Linux)
* **Orquestração/Painel:** Easypanel (VPS Hostinger)

---

## 📂 Estrutura de Containerização (Docker)

O projeto está configurado para gerar um container único de produção que roda tanto o PHP-FPM quanto o Nginx, simplificando o deploy no Easypanel. Os arquivos chave são:

* **`Dockerfile`:** Configuração multi-stage. Utiliza a imagem oficial do `php:8.4-fpm-alpine`, instala extensões essenciais (`pdo_mysql`, `bcmath`, `zip`, `opcache`, `gd`), baixa o Composer e instala as dependências. Nginx e PHP-FPM rodam sob o mesmo usuário (`www-data`) para evitar conflitos de permissão na pasta `storage`.
* **`docker/nginx.conf`:** Configuração do servidor Nginx apontando para a pasta pública do Laravel (`/public`) e direcionando requisições PHP para o PHP-FPM local (`127.0.0.1:9000`).
* **`docker/entrypoint.sh`:** Script executado na inicialização do container. Ele limpa e gera os caches do Laravel (`config:cache`, `route:cache`, `view:cache`) e inicia os serviços em background/foreground.
* **`.dockerignore`:** Impede que dependências locais (`vendor`, `node_modules`), arquivos confidenciais (`.env`) ou bancos de dados locais sejam enviados ao build da VPS.

---

## 🚀 Como Rodar Localmente (Desenvolvimento)

### Pré-requisitos
* PHP >= 8.3
* Composer
* MySQL ou SQLite local

### Passo a Passo

1. **Clonar o Repositório do Backend**
2. **Instalar dependências do PHP:**
   ```bash
   composer install
   ```
3. **Configurar o Ambiente:**
   Copie o arquivo `.env.example` para `.env` e configure as credenciais do seu banco de dados local.
   ```bash
   cp .env.example .env
   ```
4. **Gerar a chave da aplicação:**
   ```bash
   php artisan key:generate
   ```
5. **Rodar as migrations (e seeders se necessário):**
   ```bash
   php artisan migrate
   ```
6. **Iniciar o Servidor de Desenvolvimento:**
   ```bash
   php artisan serve
   ```
   A API estará acessível em `http://localhost:8000`.

---

## 🔑 Autenticação (Laravel Sanctum)

A API utiliza o Sanctum para emissão e validação de tokens de segurança.
* O Model `User` está configurado com a trait `Laravel\Sanctum\HasApiTokens`.
* As rotas públicas e privadas da API devem ser configuradas dentro de `routes/api.php`.
* Para proteger uma rota com autenticação via Token, utilize o middleware `auth:sanctum`:
  ```php
  Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
      return $request->user();
  });
  ```

---

## 🌐 Fluxo de Deploy no Easypanel (Hostinger)

O deploy é automatizado diretamente através do Git.

1. **Configuração de Origem:** No painel do Easypanel, o serviço de App está conectado a este repositório Git (branch `main`). O diretório raiz configurado é `/` (raiz do repositório).
2. **Método de Build:** Está configurado para usar o **Dockerfile**. O Easypanel detecta automaticamente o `Dockerfile` na raiz do projeto e executa o build na VPS.
3. **Banco de Dados:** O Laravel se conecta ao serviço MySQL criado no Easypanel através da rede interna do Docker.
4. **Variáveis de Ambiente:** São inseridas diretamente na aba *Environment* no Easypanel. O host do banco de dados aponta para o endereço interno:
   ```env
   DB_HOST=api-traxii_db-traxii
   DB_PORT=3306
   ```

### Executar comandos em Produção (Migrations, etc.)
Caso precise rodar migrations em produção após atualizar tabelas:
1. Acesse o Easypanel -> Serviço `backend` -> Aba **Console**.
2. Rode o comando:
   ```bash
   php artisan migrate --force
   ```
