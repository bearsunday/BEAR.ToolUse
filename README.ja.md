# BEAR.ToolUse

BEAR.SundayアプリケーションをAIエージェント対応にするライブラリ。

リソースクラスからTool Use定義を自動生成し、LLMとのエージェントループを管理します。

## 特徴

- リソースクラスからJSON Schemaベースのツール定義を自動生成
- ALPSプロファイルによるセマンティック記述の補強
- `#[Tool]`アトリビュートによる公開制御
- LLM実装に依存しない設計（インターフェイスのみ提供）

## 要件

- PHP 8.2以上
- BEAR.Sunday

## インストール

```bash
composer require bear/tool-use
```

## 使用方法

### 1. リソースクラスの定義

```php
<?php

namespace MyApp\Resource\App;

use BEAR\ToolUse\Attribute\Tool;
use BEAR\Resource\ResourceObject;

#[Tool(description: 'ユーザー情報を管理')]
class User extends ResourceObject
{
    /**
     * ユーザーを取得
     *
     * @param int $id ユーザーID
     */
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id, 'name' => 'John'];
        return $this;
    }

    /**
     * ユーザーを作成
     *
     * @param string $name ユーザー名
     * @param string $email メールアドレス
     */
    public function onPost(string $name, string $email): static
    {
        $this->body = ['id' => 1, 'name' => $name, 'email' => $email];
        return $this;
    }
}
```

### 2. LLMクライアントの実装

```php
<?php

namespace MyApp\Llm;

use BEAR\ToolUse\Llm\LlmClientInterface;
use BEAR\ToolUse\Llm\LlmResponse;
use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Schema\Tool;

final class MyLlmClient implements LlmClientInterface
{
    /**
     * @param list<Message> $messages
     * @param list<Tool> $tools
     */
    public function chat(string $system, array $messages, array $tools): LlmResponse
    {
        // LLM APIを呼び出してレスポンスを返す
    }
}
```

### 3. DIモジュールの設定

```php
<?php

namespace MyApp\Module;

use BEAR\ToolUse\Llm\LlmClientInterface;
use BEAR\ToolUse\Module\ToolUseModule;
use MyApp\Llm\MyLlmClient;
use Ray\Di\AbstractModule;

final class AppModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->install(new ToolUseModule());
        $this->bind(LlmClientInterface::class)->to(MyLlmClient::class);
    }
}
```

### 4. エージェントの実行

```php
<?php

use BEAR\ToolUse\Runtime\AgentFactory;
use MyApp\Resource\App\User;

// ファクトリーでエージェントを作成
$agent = $factory
    ->addResource(User::class, '/user')
    ->create('あなたは親切なアシスタントです。');

// エージェントを実行
$response = $agent->run('ID 123のユーザー情報を教えてください');

if ($response->completed) {
    echo $response->getText();
}
```

## ツール公開の制御

### メソッド単位での非公開

```php
use BEAR\ToolUse\Attribute\Tool;

class User extends ResourceObject
{
    public function onGet(int $id): static { /* ... */ }

    #[Tool(expose: false)]
    public function onDelete(int $id): static { /* 非公開 */ }
}
```

### クラス全体を非公開

```php
#[Tool(expose: false)]
class InternalResource extends ResourceObject
{
    // このリソースの全メソッドが非公開
}
```

### カスタムツール名

```php
#[Tool(name: 'search_users', description: 'ユーザーを検索')]
public function onGet(string $query): static { /* ... */ }
```

## ALPSによるセマンティック記述

ALPSプロファイルを使用してパラメータの説明を補強できます。

```php
use BEAR\ToolUse\Schema\AlpsSemanticDictionary;
use BEAR\ToolUse\Schema\SchemaConverter;

$dictionary = new AlpsSemanticDictionary('/path/to/profile.json');
$converter = new SchemaConverter($dictionary);
```

ALPSプロファイルの`semantic`記述子から`title`または`doc.value`がパラメータの説明として使用されます。

## アーキテクチャ

```
┌─────────────────────────────────────────────────────────────┐
│                        Agent                                 │
│  ┌─────────────┐    ┌──────────────┐    ┌───────────────┐  │
│  │ LlmClient   │───▶│   Message    │───▶│  Dispatcher   │  │
│  │ (Interface) │    │   Loop       │    │               │  │
│  └─────────────┘    └──────────────┘    └───────┬───────┘  │
│                                                  │          │
│  ┌─────────────────────────────────────────────┐│          │
│  │              ToolRegistry                    ││          │
│  │  tool_name → {resourceUri, method}          ││          │
│  └─────────────────────────────────────────────┘│          │
│                                                  ▼          │
│                                         ┌───────────────┐  │
│                                         │ BEAR.Resource │  │
│                                         └───────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

## API

### インターフェイス

| インターフェイス | 説明 |
|-----------------|------|
| `LlmClientInterface` | LLM APIクライアント（ユーザー実装） |
| `DispatcherInterface` | ツール呼び出しのディスパッチ |
| `ToolRegistryInterface` | ツール名とリソースのマッピング |
| `SchemaConverterInterface` | リソースからツール定義への変換 |
| `ToolCollectorInterface` | ツールの収集と登録 |
| `AgentInterface` | エージェントランタイム |

### 主要クラス

| クラス | 説明 |
|-------|------|
| `Agent` | LLMとの会話ループを管理 |
| `AgentFactory` | エージェントのビルダー |
| `AgentResponse` | エージェント実行結果 |
| `Tool` | ツール定義（JSON Schema） |
| `ToolCall` | LLMからのツール呼び出し |
| `ToolResult` | ツール実行結果 |
| `Message` | 会話メッセージ |
| `LlmResponse` | LLMからのレスポンス |

## 開発

```bash
# 開発ツールのセットアップ
composer setup

# テスト実行
composer test

# コーディング規約チェック
composer cs

# 静的解析
composer sa

# 全チェック実行
composer tests
```

## ライセンス

MIT License
