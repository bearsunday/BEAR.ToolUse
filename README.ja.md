# BEAR.ToolUse

BEAR.SundayアプリケーションをAIエージェント対応にするライブラリ。

リソースクラスからTool Use定義を自動生成し、LLMとのエージェントループを管理します。

## 特徴

- リソースクラスからJSON Schemaベースのツール定義を自動生成
- JSON Schema、ALPSプロファイル、PHPDocによるパラメータ説明の補強
- `#[Tool]`と`#[Exclude]`アトリビュートによる公開制御
- URIベースのリソース指定（`app://self/user`、`page://self/article`）
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

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;

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

// ファクトリーでエージェントを作成（URIベース）
$agent = $factory
    ->addResources([
        'app://self/user',
        'app://self/article',
        'page://self/search',
    ])
    ->create('あなたは親切なアシスタントです。');

// エージェントを実行
$response = $agent->run('ID 123のユーザー情報を教えてください');

if ($response->completed) {
    echo $response->getText();
}
```

### 5. 呼び出し単位のツール制限

`AgentOptions` を使うと、1回の `run()` で利用できるツールを制限できます。収集済みの Resource registry は変更せず、その呼び出しで LLM に渡す tool list だけを絞ります。

```php
use BEAR\ToolUse\Runtime\AgentOptions;

$response = $agent->run(
    '記事を検索してください。変更はしないでください。',
    AgentOptions::withTools(['article_get', 'search_get']),
);
```

存在しない tool 名を指定した場合は、LLM 呼び出し前に例外になります。typo や policy 設定ミスを早期に検出できます。

Streaming Agent でも同じ option を使えます。

```php
foreach ($agent->runStream('記事を検索して', AgentOptions::withTools(['search_get'])) as $event) {
    // ...
}
```

### 6. Input/Output Processor

Processor を使うと、Agent runtime を肥大化させずに各 LLM 呼び出しを拡張できます。Input Processor は LLM 呼び出し前に system prompt、messages、tools を加工できます。Output Processor は LLM 呼び出し後の response を検査・正規化できます。tool result 後の再問い合わせを含め、各 iteration で毎回適用されます。

```php
use BEAR\ToolUse\Runtime\AgentOptions;
use BEAR\ToolUse\Runtime\InputProcessorInterface;
use BEAR\ToolUse\Runtime\LlmRequest;
use BEAR\ToolUse\Runtime\Message;

final class MemoryProcessor implements InputProcessorInterface
{
    public function process(LlmRequest $request): LlmRequest
    {
        return $request->withMessages([
            ...$request->messages,
            Message::user('既知の文脈: ユーザーは簡潔な回答を好む。'),
        ]);
    }
}

$response = $agent->run(
    'この記事を要約して',
    AgentOptions::withProcessors(inputProcessors: [new MemoryProcessor()]),
);
```

`OutputProcessorInterface` は通常の Agent では `LlmResponse`、Streaming Agent では `StreamEvent` を受け取ります。受け取ったものと同じ具体型を返す必要があります。text content は書き換えられますが、runtime が tool call を安全に dispatch できるように tool-use の制御データは保持してください。

`AlpsContextInputProcessor` を使うと、ALPS を runtime context として注入できます。現在の LLM 呼び出しで利用可能な tool に一致する `safe` / `unsafe` などの transition descriptor と、tool input に一致する semantic descriptor を追加します。tool descriptor は tool 名（または camelCase 形）で照合されるため、`article_get` tool には `article_get` または `articleGet` のような ALPS descriptor id が必要です。tool input parameter は semantic descriptor のみを参照します。

```php
use BEAR\ToolUse\Runtime\AgentOptions;
use BEAR\ToolUse\Runtime\AlpsContextInputProcessor;
use BEAR\ToolUse\Runtime\AlpsToolPolicyInputProcessor;
use BEAR\ToolUse\Schema\AlpsSemanticDictionary;

$alps = new AlpsSemanticDictionary(__DIR__ . '/alps/profile.json');

$response = $agent->run(
    'このユーザーを探して',
    AgentOptions::withProcessors(inputProcessors: [
        new AlpsContextInputProcessor($alps),
    ]),
);
```

ALPS の transition type を呼び出し単位の tool policy として使うこともできます。`safeOnly()` policy は、一致する ALPS descriptor が `safe` の tool だけを公開します。一致する descriptor がない tool は、`safeOnlyAllowingUnknownTools()` を使わない限り隠されます。冪等だが状態変更を伴う transition も許可する場合は `safeAndIdempotent()` を使います。この policy は、期待する tool が ALPS profile で網羅されている状態で使うか、移行中は unknown 許可 variant を選んでください。policy と context processor を組み合わせる場合は、policy を先に置くことで `AlpsContextInputProcessor` がフィルタ後に残った tool だけを説明できます。

```php
$response = $agent->run(
    '現在のアカウント状態を変更せずに要約して',
    AgentOptions::withProcessors(inputProcessors: [
        AlpsToolPolicyInputProcessor::safeOnly($alps),
        new AlpsContextInputProcessor($alps),
    ]),
);
```

### 7. Agent-as-Tool / Named Subagent

専門エージェントを `AgentPool` に登録すると、`ask_{name}` という tool として公開できます。Subagent の会話履歴は呼び出しごとに隔離されます。

```php
use BEAR\ToolUse\Runtime\AgentDelegator;
use BEAR\ToolUse\Runtime\AgentFactory;
use BEAR\ToolUse\Runtime\AgentPool;
use BEAR\ToolUse\Runtime\AgentProfile;

$pool = new AgentPool($llmClient, $resourceDispatcher, $collector);
$pool->register(new AgentProfile(
    name: 'critic',
    description: '設計上のリスクをレビューする',
    systemPrompt: 'You are a critical reviewer.',
    resources: ['app://self/article'],
));

$delegator = new AgentDelegator($pool, $resourceDispatcher);
$factory = new AgentFactory($llmClient, $delegator, $collector, $registry);

$agent = $factory
    ->addResources(['app://self/article'])
    ->addSubagents($pool)
    ->create('あなたは調整役のエージェントです。');

// LLM が ask_critic を呼ぶと、AgentDelegator が critic agent を実行し、
// 結果を通常の tool_result として LLM に返します。
```

Subagent は直接呼び出すこともできます。

```php
$response = $delegator->ask('critic', 'この設計のリスクは？', ['articleId' => 1]);
```

`AgentPool` が作成する Subagent は、pool に `ConfirmationHandlerInterface` が設定されていない場合、確認対象 tool をデフォルトで拒否します。Subagent に `#[Tool(confirm: true)]` の resource 実行を許可したい場合は、`AgentPool` に confirmation handler を渡してください。

### 8. 会話履歴

エージェントは複数の `run()` 呼び出しにわたって会話履歴を保持します。

```php
// 会話を継続
$response = $agent->run('そのユーザーのメールアドレスは？');

// メッセージ履歴にアクセス
$messages = $agent->messages;

// 後で使用するために保存（例：DBやセッションに）
$savedHistory = $agent->messages;

// 会話を復元して継続
$agent->messages = $savedHistory;
$response = $agent->run('このユーザーについてもっと教えて');

// 履歴をクリアして新しい会話を開始
$agent->reset();
```

`AgentResponse::$messages` は、`Agent::run()` から返された場合は停止時点の会話履歴スナップショットです。`AgentResponse::completed($content)` などの factory を直接呼ぶ場合は、明示的に渡さない限り履歴は空です。

### 9. ストリーミングエージェント

リアルタイム出力（SSE、WebSocket）には、ストリーミングエージェントを使用します。LLMの出力に応じてイベントをyieldします。

```php
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;

// DIモジュールでストリーミングクライアントをバインド
$this->bind(StreamingLlmClientInterface::class)->to(MyStreamingLlmClient::class);
```

```php
// ストリーミングエージェントを作成
$agent = $factory
    ->addResources(['app://self/user', 'app://self/article'])
    ->createStreaming('あなたは親切なアシスタントです。');

// イベントを処理
$gen = $agent->runStream('ユーザー123を取得して');
while ($gen->valid()) {
    $event = $gen->current();
    match ($event->type) {
        'text_delta'            => sendSseEvent('text', $event->data['text']),
        'tool_start'            => sendSseEvent('status', "{$event->data['toolName']}を呼び出し中..."),
        'tool_result'           => sendSseEvent('status', "{$event->data['toolName']}完了"),
        'confirmation_required' => sendSseEvent('confirm', json_encode($event)),
        'completed'             => sendSseEvent('done', $event->data['fullText']),
        'error'                 => sendSseEvent('error', $event->data['message']),
    };
    // 確認イベントの場合、Generator::send()でユーザーの応答を送信
    if ($event->type === 'confirmation_required') {
        $approved = waitForUserConfirmation(); // アプリケーション固有のロジック
        $gen->send($approved);
    } else {
        $gen->next();
    }
}
```

`AgentEvent` は `JsonSerializable` を実装しており、SSEレスポンスで直接利用できます：

```php
echo "data: " . json_encode($event) . "\n\n";
```

## ツール公開の制御

### メソッド単位での除外

```php
use BEAR\ToolUse\Attribute\Exclude;

class User extends ResourceObject
{
    public function onGet(int $id): static { /* 公開される */ }

    #[Exclude]
    public function onDelete(int $id): static { /* 除外 */ }
}
```

### クラス全体を除外

```php
use BEAR\ToolUse\Attribute\Exclude;

#[Exclude]
class InternalResource extends ResourceObject
{
    // このリソースの全メソッドが除外
}
```

### カスタムツール名と説明

```php
use BEAR\ToolUse\Attribute\Tool;

#[Tool(name: 'search_users', description: 'ユーザーを検索')]
public function onGet(string $query): static { /* ... */ }
```

## Human-in-the-Loop 確認

`confirm: true` を指定すると、破壊的なツール呼び出しの実行前にユーザーの確認を求めます。

### 確認が必要なツールの指定

```php
use BEAR\ToolUse\Attribute\Tool;

// クラスレベル - 全メソッドで確認が必要
#[Tool(confirm: true)]
class User extends ResourceObject
{
    public function onGet(int $id): static { /* ... */ }
    public function onDelete(int $id): static { /* ... */ }
}

// メソッドレベル - 特定のメソッドのみ確認が必要
class Article extends ResourceObject
{
    public function onGet(int $id): static { /* ... */ }

    #[Tool(confirm: true)]
    public function onDelete(int $id): static { /* ... */ }
}
```

### 確認ハンドラーの実装

```php
use BEAR\ToolUse\Runtime\ConfirmationHandlerInterface;
use BEAR\ToolUse\Dispatch\ToolCall;

final class CliConfirmationHandler implements ConfirmationHandlerInterface
{
    public function confirm(ToolCall $toolCall, string $llmText): bool
    {
        echo $llmText . "\n実行しますか？ [Y/n]: ";

        $line = fgets(STDIN);

        return $line !== false && trim($line) !== 'n';
    }
}
```

### DIモジュールでのバインド

```php
$this->bind(ConfirmationHandlerInterface::class)->to(CliConfirmationHandler::class);
```

### 動作の仕組み

LLMのテキストレスポンスが確認メッセージとして使われます。テンプレートは不要です。

```text
ユーザー: 「記事123を削除して」
  ↓
LLM: 「記事ID 123「BEAR.Sundayの紹介」を削除します。」
     tool_use: article_delete({id: 123})
  ↓
ConfirmationHandler: 「記事ID 123「BEAR.Sundayの紹介」を削除します。」
                     実行しますか？ [Y/n]:
  ↓
Y → ツール実行
N → "User cancelled this operation." → LLM: 「承知しました。」
```

直接作成した `Agent` では、`ConfirmationHandlerInterface` がバインドされていない場合、確認対象ツールも通常通り実行されます（ブロックなし）。一方、`AgentPool` が作成する Subagent はより保守的で、pool に confirmation handler がない場合、確認対象の Subagent tool call はデフォルトでキャンセルされます。

### ストリーミングエージェントでの確認

`StreamingAgent` は `ConfirmationHandlerInterface` の代わりに yield-based アプローチを使用します。確認が必要なツールに遭遇すると `confirmation_required` イベントを yield し、`Generator::send(bool)` でユーザーの応答を受け取ります。

```text
StreamingAgent が yield: confirmation_required (toolName, input, message)
  ↓
SSE で確認イベントをクライアントに送信 → クライアントがUIを表示
  ↓
クライアントが別のHTTPリクエストで応答
  ↓
サーバーが呼び出し: $generator->send(true)  // false でキャンセル
  ↓
StreamingAgent が再開: ツール実行またはキャンセル
```

`send()` が呼ばれない場合（例: `iterator_to_array()`）、ツールは**デフォルトで拒否**されます（安全なデフォルト）。

## レスポンスフィルタリング

`filter` を使用して、LLMに送信する前にレスポンスボディを削減できます。大量のデータを返すリソースでトークン効率を改善します。

### フィルタの定義

```php
use BEAR\ToolUse\Dispatch\ToolResultFilterInterface;
use Override;

final readonly class SummaryFilter implements ToolResultFilterInterface
{
    #[Override]
    public function __invoke(mixed $body): mixed
    {
        // LLMに必要なフィールドだけを抽出
        return array_map(fn (array $item) => [
            'id' => $item['id'],
            'title' => $item['title'],
        ], $body);
    }
}
```

### リソースへの適用

```php
use BEAR\ToolUse\Attribute\Tool;

// クラスレベル - 全メソッドでフィルタを使用
#[Tool(filter: SummaryFilter::class)]
class Search extends ResourceObject
{
    public function onGet(string $query): static { /* ... */ }
}

// メソッドレベル - 特定のメソッドのみフィルタを使用
class Article extends ResourceObject
{
    #[Tool(filter: SummaryFilter::class)]
    public function onGet(string $query): static { /* ... */ }

    public function onPost(string $title, string $body): static { /* ... */ }
}
```

フィルタは成功レスポンスにのみ適用されます。エラーレスポンス（ステータスコード >= 400）はフィルタされずにそのまま送信されます。

## ツール呼び出しの観測

すべてのツール呼び出しをフックして、監査ログ・メトリクス・レイテンシ計測などを行えます。Observer はディスパッチごとに 1 回だけ呼び出され、成功・ステータスコードエラー・例外・未知ツールのどの経路でも `ToolCall`、`ToolResult`、経過時間（ミリ秒）を受け取ります。

### Observer の実装

```php
use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolCallObserverInterface;
use BEAR\ToolUse\Dispatch\ToolResult;
use Override;

final readonly class AuditLogObserver implements ToolCallObserverInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    #[Override]
    public function observe(ToolCall $toolCall, ToolResult $result, float $durationMs): void
    {
        $this->logger->info('tool_call', [
            'name' => $toolCall->name,
            'input' => $toolCall->input,
            'isError' => $result->isError,
            'durationMs' => $durationMs,
        ]);
    }
}
```

> **Note:** `$toolCall->input` や `$result->content` には、利用するリソース次第で機密情報（個人情報、認証情報、API トークンなど）が含まれる可能性があります。永続ログ・トレース・外部システムへ送る前に、該当フィールドのサニタイズ／マスキングを行ってください。

### DI モジュールでバインド

```php
$this->bind(ToolCallObserverInterface::class)->to(AuditLogObserver::class);
```

Observer がバインドされていない場合、デフォルトで `NullToolCallObserver`（no-op）が使用されます。

### 設計上の補足

- Observer に渡される `ToolResult` はレスポンスフィルタ適用**後**のもの、つまり LLM が実際に受け取る形です。
- インターフェイスは意図的に最小限です。スレッドID／会話ID／ユーザーIDなどアプリケーション固有のコンテキストは、利用者側のステートフルな Observer 実装で扱うべきです（インターフェイス引数には含めません）。
- `Dispatcher` は分岐に関わらず、ディスパッチごとに必ず 1 回 Observer を呼び出します。
- **キャンセルされたツール呼び出しは Dispatcher を経由しません。** 確認拒否は `Agent` / `StreamingAgent` 層（`ConfirmationHandlerInterface` または `Generator::send(false)`）で処理され、`Dispatcher::dispatch()` を呼ばずに `ToolResult::cancelled()` を返します。そのため Observer はキャンセル時には**呼び出されません**。
- **`observe()` から throw された例外は `Dispatcher::dispatch()` の外へ伝播します。** 観測の失敗でツール呼び出しを止めたくない場合、Observer 実装側で I/O を try/catch するなどして自身でエラーハンドリングする責任があります。

## JSON Schemaの統合

BEAR.ResourceのJSON Schemaを使用してパラメータ定義を強化できます。

### 1. JsonSchemaModuleと共にインストール

```php
use BEAR\Resource\Module\JsonSchemaModule;
use BEAR\ToolUse\Module\ToolUseModule;

$this->install(
    new JsonSchemaModule(
        $this->appMeta->appDir . '/var/json_schema',
        $this->appMeta->appDir . '/var/json_validate',
    ),
);
$this->install(new ToolUseModule());
```

### 2. JSON Schemaの定義

```json
// /path/to/validate/user.json
{
    "type": "object",
    "properties": {
        "id": {
            "type": "integer",
            "description": "ユーザーID",
            "minimum": 1
        },
        "status": {
            "type": "string",
            "description": "ユーザーステータス",
            "enum": ["active", "inactive", "pending"]
        }
    }
}
```

### 3. リソースへの適用

```php
use BEAR\Resource\Annotation\JsonSchema;

class User extends ResourceObject
{
    #[JsonSchema(params: 'user.json')]
    public function onGet(int $id, string $status = 'active'): static
    {
        // JSON Schemaによるランタイムバリデーションとツール定義の両方に使用
    }
}
```

JSON Schemaから以下のプロパティが抽出されます：
- `description` - パラメータの説明
- `enum` - 許可される値
- `format` - 値のフォーマット（email、uri、date等）
- `minimum` / `maximum` - 数値の範囲
- `minLength` / `maxLength` - 文字列の長さ
- `pattern` - 正規表現パターン

## ALPSによるセマンティック記述

ALPSプロファイルを使用してパラメータの説明を補強できます。

```php
use BEAR\ToolUse\Schema\AlpsSemanticDictionary;
use BEAR\ToolUse\Schema\SchemaConverter;

$dictionary = new AlpsSemanticDictionary('/path/to/profile.json');
$converter = new SchemaConverter($dictionary);
```

**JSON**と**XML**の両形式の ALPS プロファイルをサポートしています（ファイル拡張子で自動判別）。`semantic`記述子の`title`または`doc`がパラメータの説明として使用されます。同一プロファイル内の`href="#id"`参照は自動解決されます。パラメータ説明では`safe` / `unsafe` / `idempotent`（トランジション）記述子は除外されますが、`AlpsContextInputProcessor` は一致する transition descriptor を runtime context として利用できます。

## パラメータ説明の優先順位

複数のソースが説明を提供する場合、以下の順序で解決されます：

1. **JSON Schema** - スキーマファイルの`description`プロパティ（+ `enum`、`format`、`min/max`等の制約）
2. **PHPDoc** - `@param`タグの説明（メソッド固有）
3. **ALPS** - セマンティック記述子の`title`または`doc.value`（アプリケーション全体のフォールバック）

## アーキテクチャ

```
┌─────────────────────────────────────────────────────────────┐
│                        Agent                                │
│  ┌─────────────┐    ┌──────────────┐    ┌───────────────┐   │
│  │ LlmClient   │───▶│   Message    │───▶│  Dispatcher   │   │
│  │ (Interface) │    │   Loop       │    │               │   │
│  └─────────────┘    └──────────────┘    └───────┬───────┘   │
│                                                 │           │
│  ┌─────────────────────────────────────────────┐│           │
│  │              ToolRegistry                   ││           │
│  │  tool_name → {resourceUri, method}          ││           │
│  └─────────────────────────────────────────────┘│           │
│                                                 ▼           │
│                                         ┌───────────────┐   │
│                                         │ BEAR.Resource │   │
│                                         └───────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

## エラーフィードバックループ

ツール実行が失敗した場合、エラーは自動的にLLMにフィードバックされ、LLMはパラメータを修正して再試行したり、別のアクションを取ることができます。例外ベースのエラーと非2xxステータスコードの両方に対応しています。

```
ユーザー: 「ユーザー999を削除して」
  ↓
LLM: tool_use → user_delete(id: 999)
  ↓
Dispatcher: 404 Not Found → ToolResult(isError: true)
  ↓
LLMがエラーを受信し、次のアクションを決定
  ↓
LLM: 「ユーザー999は見つかりませんでした。」
```

Dispatcherが検出するエラー:

| エラー種別 | 例 | エラーメッセージ形式 |
|-----------|---|-------------------|
| 例外 | `ResourceNotFoundException` | `BEAR\Resource\Exception\ResourceNotFoundException: /user?id=999` |
| ステータスコード | `$this->code = 400` | `400: {"error":"Validation failed"}` |
| 未知のツール | 未登録のツール | `Unknown tool: foo_bar` |

## API

### インターフェイス

| インターフェイス | 説明 |
|-----------------|------|
| `LlmClientInterface` | LLM APIクライアント（ユーザー実装） |
| `StreamingLlmClientInterface` | ストリーミングLLM APIクライアント（ユーザー実装） |
| `DispatcherInterface` | ツール呼び出しのディスパッチ |
| `ToolRegistryInterface` | ツール名とリソースのマッピング |
| `SchemaConverterInterface` | リソースからツール定義への変換 |
| `ToolCollectorInterface` | ツールの収集と登録 |
| `AgentInterface` | エージェントランタイム |
| `OptionAwareAgentInterface` | 呼び出し単位の `AgentOptions` に対応したエージェントランタイム |
| `StreamingAgentInterface` | ストリーミングエージェントランタイム |
| `OptionAwareStreamingAgentInterface` | 呼び出し単位の `AgentOptions` に対応したストリーミングエージェントランタイム |
| `ToolResultFilterInterface` | LLM送信前のレスポンスフィルタ |
| `InputProcessorInterface` | LLM 呼び出し前に request を処理 |
| `OutputProcessorInterface` | LLM 呼び出し後に response または stream event を処理 |
| `ConfirmationHandlerInterface` | 破壊的ツールのユーザー確認 |
| `ToolCallObserverInterface` | ディスパッチごとに 1 回呼ばれるフック（監査・メトリクス・レイテンシ計測） |

### 主要クラス

| クラス | 説明 |
|-------|------|
| `Agent` | LLMとの会話ループを管理 |
| `StreamingAgent` | `AgentEvent`をyieldするストリーミング会話ループ |
| `AgentFactory` | エージェントのビルダー（同期・ストリーミング） |
| `AgentOptions` | ツール制限などの呼び出し単位オプション |
| `LlmRequest` | Input Processor に渡される LLM request |
| `AlpsContextInputProcessor` | 関連する ALPS descriptor を各 LLM request に追加 |
| `AlpsToolPolicyInputProcessor` | ALPS transition type に一致する tool だけに絞り込み |
| `AgentProfile` | Named Subagent の設定 |
| `AgentPool` | Named Subagent の登録・作成 |
| `AgentDelegator` | `ask_*` tool call を Subagent に委譲 |
| `AgentResponse` | エージェント実行結果（同期） |
| `AgentEvent` | ストリーミングイベント（`JsonSerializable`） |
| `StreamEvent` | 低レベルLLMストリームイベント |
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
