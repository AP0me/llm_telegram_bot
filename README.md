# LLM Telegram Bot

[![Ask DeepWiki](https://devin.ai/assets/askdeepwiki.png)](https://deepwiki.com/AP0me/llm_telegram_bot)

This project is a sophisticated AI-powered chatbot for automated appointment booking built on the Laravel framework. It integrates with Telegram to provide an interactive chat experience and uses the OpenRouter service to connect with a variety of Large Language Models (LLMs) like DeepSeek. The application features both a Telegram bot interface and a simple web-based chat UI.

## Features

- **Telegram Bot Integration:** Leverages the `irazasyed/telegram-bot-sdk` to listen for and respond to messages on Telegram.
- **LLM Connectivity via OpenRouter:** A dedicated service, `OpenRouter.php`, manages all interactions with the OpenRouter API, making it easy to swap different LLMs.
- **Streaming Responses:** Believable, real-time responses are streamed token-by-token to both the web UI and the Telegram client.
- **Stateful Conversation Management:** Chat sessions, prompts, and LLM answers are persistently stored in a database, allowing the bot to maintain context throughout a conversation.
- **Command Handling:** The bot recognizes commands like `/start` and `/stop` to manage the lifecycle of an LLM chat session.
- **Web-Based Chat Interface:** A secure, authenticated web UI allows users to interact with the LLM through their browser.
- **Database-Driven History:** A comprehensive set of database migrations structures the storage of chats, messages, prompts, answers, and LLM sessions.
- **Authentication:** User registration and login are handled by Laravel Fortify.
- **LLM Function Calling:** Includes a basic implementation of LLM tool/function calling with a sample `get_weather` function.

## Architecture

The application is a standard Laravel project with a few key components driving the bot's functionality:

- **Console Command Polling (`routes/console.php`):** The core of the bot is a long-running Artisan command, `php artisan telegram`. This command continuously polls the Telegram API for new messages.
- **OpenRouter Service (`app/Services/OpenRouter.php`):** This service is the gateway to the OpenRouter API. It handles building the request payload, making the API call, and processing the streamed response.
- **Telegram Service (`app/Services/TelegramService.php`):** This service contains the logic for handling bot commands (`/start`, `/stop`) and for buffering and sending the LLM's streamed responses back to the user in complete sentences.
- **Web Interface (`routes/web.php`, `LLMController.php`, `chat.blade.php`):** Provides a web-based chat experience. The `LLMController` streams the model's output directly into an `<iframe>` on the chat page.
- **Database Schema:**
    - `llm_sessions`: Tracks the start and end of a conversation with the bot.
    - `prompts`: Stores each user message sent during an active LLM session.
    - `llm_answers`: Stores the corresponding response and reasoning from the AI.
    - `messages`, `commands`, `chats`: Store raw data from the Telegram API.

## Setup and Installation

### Prerequisites

- PHP 8.2+
- Composer
- Node.js & npm
- A database (SQLite is configured by default)
- A Telegram Bot Token
- An OpenRouter API Token

### Installation Steps

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/ap0me/llm_telegram_bot.git
    cd llm_telegram_bot
    ```

2.  **Install dependencies:**
    ```bash
    composer install
    npm install
    npm run build
    ```

3.  **Configure your environment:**
    - Copy the example environment file:
      ```bash
      cp .env.example .env
      ```
    - Generate an application key:
      ```bash
      php artisan key:generate
      ```
    - Edit your `.env` file and add your credentials:
      ```env
      # Database configuration (defaults to a new sqlite file)
      DB_CONNECTION=sqlite

      # Telegram Bot Token from BotFather
      TELEGRAM_BOT_TOKEN=YOUR_TELEGRAM_BOT_TOKEN

      # OpenRouter API Key
      OPENROUTER_TOKEN=YOUR_OPENROUTER_API_KEY
      ```

4.  **Run database migrations:**
    This will create all the necessary tables for the application, including users, sessions, and the bot's conversation history.
    ```bash
    php artisan migrate
    ```

## Usage

### 1. Start the Telegram Bot

To begin listening for messages from Telegram, run the `telegram` Artisan command. This is a long-running process that continuously polls the Telegram API.

```bash
php artisan telegram
```

For production environments, it is recommended to run this command with a process manager like Supervisor to ensure it stays active.

### 2. Interact via Telegram

- Find your bot on Telegram.
- Send `/start` to begin a conversation session.
- Send messages to chat with the LLM.
- Send `/stop` to end the current session. The bot will not respond to general messages until you start a new session.

### 3. Use the Web Interface

1.  **Start the local development server:**
    ```bash
    php artisan serve
    ```
2.  Navigate to `http://127.0.0.1:8000` in your browser.
3.  Register a new user account or log in.
4.  You will be redirected to the chat interface where you can interact with the LLM.
