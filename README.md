# LLM Telegram Bot

This project is a AI-powered chatbot for automated appointment booking built on the Laravel framework. It integrates with Telegram to provide an interactive chat experience and uses the OpenRouter service to connect with a variety of Large Language Models (LLMs) like DeepSeek.

## Features

- **Telegram Bot Integration:** Leverages the `irazasyed/telegram-bot-sdk` to listen for and respond to messages on Telegram.
- **LLM Connectivity via OpenRouter:** A dedicated service, `OpenRouter.php`, manages all interactions with the OpenRouter API, making it easy to swap different LLMs.
- **Streaming Responses:** Believable, real-time responses are streamed token-by-token to the Telegram client.
- **Stateful Conversation Management:** Chat sessions, prompts, and LLM answers are persistently stored in a database, allowing the bot to maintain context throughout a conversation.
- **Command Handling:** The bot recognizes commands like `/start` and `/stop` to manage the lifecycle of an LLM chat session.
- **Database-Driven History:** A comprehensive set of database migrations structures the storage of chats, messages, prompts, answers, and LLM sessions.
- **LLM Function Calling:** Includes a LLM tool/function calling mechanism.

## Architecture

The application is a standard Laravel project with a few key components driving the bot's functionality:

- **Console Command Polling (`routes/console.php`):** The core of the bot is a long-running Artisan command, `php artisan telegram`. This command continuously polls the Telegram API for new messages.
- **OpenRouter Service (`app/Services/OpenRouter.php`):** This service is the gateway to the OpenRouter API. It handles building the request payload, making the API call, and processing the streamed response.
- **Telegram Service (`app/Services/TelegramService.php`):** This service contains the logic for handling bot commands (`/start`, `/stop`) and for buffering and sending the LLM's streamed responses back to the user in complete sentences.

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
    ```

3.  **Configure your environment:**
    - Copy the example environment file:
      ```bash
      cp .env.example .env
      ```
    - Edit your `.env` file and add your credentials:
      ```env
      # Telegram Bot Token from BotFather
      TEST_TELEGRAM_BOT_TOKEN=YOUR_TELEGRAM_BOT_TOKEN
      PROD_TELEGRAM_BOT_TOKEN=YOUR_TELEGRAM_BOT_TOKEN

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

