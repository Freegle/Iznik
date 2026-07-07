<?php
/**
 * V1 Behavior Extractor
 *
 * Parses a V1 PHP API endpoint using PHP-Parser and recursively traverses
 * all referenced class files in include/, emitting a JSON ledger of every
 * SQL query, email send, push notification, audit log entry, and HTTP call.
 *
 * Usage (inside freegle-apiv1 container):
 *   php /var/www/iznik/scripts/parsers/v1-behavior-extractor.php \
 *       /var/www/iznik/http/api/comment.php
 *
 * Output: JSON array of behavior objects to stdout. Errors/progress to stderr.
 */

require_once '/var/www/iznik/composer/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\NodeFinder;

class BehaviorCollector extends NodeVisitorAbstract {
    public array $behaviors         = [];
    public array $classesReferenced = [];

    private string $currentFile = '';

    private const SQL_METHODS   = ['preQuery', 'prePrepared', 'preExec', 'preQueryCol', 'beginTransaction'];
    private const EMAIL_METHODS = ['getMailer', 'sendOne'];
    private const EMAIL_CLASSES = ['Mail', 'Mailer'];
    private const PUSH_CLASSES  = ['PushNotifications', 'Notifications'];
    private const LOG_METHODS   = ['log', 'logModAction', 'logGroupAction'];
    public function setFile(string $file): void {
        $this->currentFile = $file;
    }

    public function enterNode(Node $node): void {
        if ($node instanceof Node\Expr\MethodCall) {
            if (!($node->name instanceof Node\Identifier)) {
                return;
            }
            $method = $node->name->toString();
            $line   = $node->getLine();

            // Skip calls on non-MySQL DB objects (e.g. $pgsql is a PostgreSQL/PostGIS
            // connection used for batch spatial indexing — not V2 API behaviour).
            if ($node->var instanceof Node\Expr\Variable) {
                $varName = is_string($node->var->name) ? $node->var->name : '';
                if (str_contains($varName, 'pgsql') || str_contains($varName, 'pg_')) {
                    return;
                }
            }

            if (in_array($method, self::SQL_METHODS, true)) {
                $sql = $this->firstStringArg($node);
                $this->record('SQL', $method . ': ' . ($sql ?? '[expr]'), $line);
            } elseif (in_array($method, self::EMAIL_METHODS, true)) {
                $this->record('Email', $method, $line);
            } elseif (in_array($method, self::LOG_METHODS, true)) {
                $this->record('AuditLog', $method, $line);
            }
        } elseif ($node instanceof Node\Expr\StaticCall) {
            if (!($node->class instanceof Node\Name)) {
                return;
            }
            $class  = $node->class->getLast();
            $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '[dynamic]';
            $line   = $node->getLine();

            $this->classesReferenced[] = $class;

            if (in_array($class, self::PUSH_CLASSES, true)) {
                $this->record('Push', "$class::$method", $line);
            } elseif (in_array($class, self::EMAIL_CLASSES, true)) {
                $this->record('Email', "$class::$method", $line);
            }
        } elseif ($node instanceof Node\Expr\New_) {
            if ($node->class instanceof Node\Name) {
                $this->classesReferenced[] = $node->class->getLast();
            }
        } elseif ($node instanceof Node\Expr\FuncCall) {
            if (!($node->name instanceof Node\Name)) {
                return;
            }
            $func = $node->name->toString();
            if ($func === 'curl_exec') {
                $this->record('HTTP', $func, $node->getLine());
            } elseif ($func === 'file_get_contents') {
                // Only flag remote URL fetches, not local file reads
                $url = $this->firstStringArg($node);
                if ($url === null || str_starts_with($url, 'http')) {
                    $this->record('HTTP', $func . ($url ? ': ' . $url : ''), $node->getLine());
                }
            }
        }
    }

    private function record(string $category, string $description, int $line): void {
        $this->behaviors[] = [
            'category'    => $category,
            'description' => $description,
            'file'        => $this->currentFile,
            'line'        => $line,
        ];
    }

    private function firstStringArg(Node\Expr\CallLike $node): ?string {
        if (empty($node->args)) {
            return null;
        }
        $arg = $node->args[0];
        if ($arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_) {
            return $arg->value->value;
        }
        return null;
    }
}

function buildClassIndex(string $includeRoot, \PhpParser\Parser $parser): array {
    $index  = [];
    $finder = new NodeFinder();
    $iter   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($includeRoot));

    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = @file_get_contents($file->getPathname());
        if (!$code) {
            continue;
        }
        try {
            $ast = $parser->parse($code);
        } catch (\Exception $e) {
            fwrite(STDERR, "Index parse error in {$file->getPathname()}: {$e->getMessage()}\n");
            continue;
        }
        foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $class) {
            if ($class->name) {
                $index[$class->name->toString()] = $file->getPathname();
            }
        }
    }
    return $index;
}

function traverseFile(
    string            $filePath,
    BehaviorCollector $collector,
    \PhpParser\Parser  $parser,
    string            $baseDir
): void {
    $code = @file_get_contents($filePath);
    if (!$code) {
        return;
    }
    try {
        $ast = $parser->parse($code);
    } catch (\Exception $e) {
        fwrite(STDERR, "Parse error in $filePath: {$e->getMessage()}\n");
        return;
    }

    $rel = ltrim(str_replace($baseDir, '', $filePath), '/');
    $collector->setFile($rel);

    $traverser = new NodeTraverser();
    $traverser->addVisitor($collector);
    $traverser->traverse($ast);
}

// Main
$endpointFile = $argv[1] ?? null;
if (!$endpointFile || !file_exists($endpointFile)) {
    fwrite(STDERR, "Usage: php v1-behavior-extractor.php <endpoint.php>\n");
    exit(1);
}

$baseDir     = '/var/www/iznik';
$includeRoot = $baseDir . '/include';
$parser      = (new ParserFactory())->createForNewestSupportedVersion();

fwrite(STDERR, "Building class index from $includeRoot...\n");
$classIndex = buildClassIndex($includeRoot, $parser);
fwrite(STDERR, count($classIndex) . " classes indexed.\n");

$collector = new BehaviorCollector();
$visited   = [];

// Pass 1: parse the endpoint file itself
$realEndpoint = realpath($endpointFile);
$visited[$realEndpoint] = true;
traverseFile($endpointFile, $collector, $parser, $baseDir);

// Pass 2 & 3: recursively parse referenced class files (up to 3 levels)
for ($depth = 0; $depth < 3; $depth++) {
    $classes  = array_unique($collector->classesReferenced);
    $newFiles = [];

    foreach ($classes as $class) {
        if (!isset($classIndex[$class])) {
            continue;
        }
        $path = $classIndex[$class];
        $real = realpath($path);
        if ($real && !isset($visited[$real])) {
            $newFiles[]     = $path;
            $visited[$real] = true;
        }
    }

    if (empty($newFiles)) {
        break;
    }

    foreach ($newFiles as $f) {
        fwrite(STDERR, "Traversing $f\n");
        traverseFile($f, $collector, $parser, $baseDir);
    }
}

// Deduplicate
$seen   = [];
$unique = [];
foreach ($collector->behaviors as $b) {
    $key = $b['category'] . '|' . $b['description'] . '|' . $b['file'] . '|' . $b['line'];
    if (!isset($seen[$key])) {
        $seen[$key] = true;
        $unique[]   = $b;
    }
}

fwrite(STDERR, count($unique) . " behaviors extracted.\n");
echo json_encode($unique, JSON_PRETTY_PRINT) . "\n";
