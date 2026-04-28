<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitGenerator\ToolkitGeneratorToolkit;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;

final readonly class ToolkitFixture
{
    /** @param array<int, ToolInterface> $tools */
    public function __construct(
        public string $tmpDir,
        public ToolkitGeneratorToolkit $toolkit,
        public array $tools,
    ) {}
}

function createToolkitFixture(): ToolkitFixture
{
    $tmpDir = sys_get_temp_dir() . '/coqui-toolkit-gen-' . bin2hex(random_bytes(4));
    mkdir($tmpDir . '/packages', 0755, true);

    $toolkit = new ToolkitGeneratorToolkit(workspacePath: $tmpDir);

    return new ToolkitFixture(
        tmpDir: $tmpDir,
        toolkit: $toolkit,
        tools: $toolkit->tools(),
    );
}

function cleanupToolkitFixture(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            cleanupToolkitFixture($path);
        } else {
            unlink($path);
        }
    }

    rmdir($dir);
}

/** @param \Closure(ToolkitFixture): void $callback */
function testWithFixture(string $description, \Closure $callback): void
{
    test($description, function () use ($callback): void {
        $fixture = createToolkitFixture();

        try {
            $callback($fixture);
        } finally {
            cleanupToolkitFixture($fixture->tmpDir);
        }
    });
}

/**
 * @param array<int, ToolInterface> $tools
 */
function findTool(array $tools, string $name): ToolInterface
{
    foreach ($tools as $tool) {
        if ($tool->name() === $name) {
            return $tool;
        }
    }

    throw new RuntimeException("Tool not found: {$name}");
}

testWithFixture('provides two tools', function (ToolkitFixture $fixture): void {
    expect($fixture->tools)->toHaveCount(2);
});

testWithFixture('tool names are correct', function (ToolkitFixture $fixture): void {
    $names = array_map(
        static fn(ToolInterface $tool): string => $tool->name(),
        $fixture->tools,
    );

    expect($names)->toBe(['coqui_toolkit_create', 'coqui_toolkit_add']);
});

testWithFixture('guidelines contain XML tags', function (ToolkitFixture $fixture): void {
    $guidelines = $fixture->toolkit->guidelines();

    expect($guidelines)->toContain('<TOOLKIT-GENERATOR-GUIDELINES>');
    expect($guidelines)->toContain('</TOOLKIT-GENERATOR-GUIDELINES>');
});

testWithFixture('create rejects empty name', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_create');
    $result = $tool->execute(['name' => '', 'description' => 'Test']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('name');
});

testWithFixture('create rejects empty description', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_create');
    $result = $tool->execute(['name' => 'test-pkg', 'description' => '']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('Description');
});

testWithFixture('create generates correct directory structure', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_create');
    $result = $tool->execute([
        'name' => 'test-toolkit',
        'description' => 'A test toolkit',
    ]);

    expect($result->status->value)->toBe('success');

    $pkgDir = $fixture->tmpDir . '/packages/test-toolkit';
    expect(is_dir($pkgDir))->toBeTrue();
    expect(is_dir($pkgDir . '/src'))->toBeTrue();
    expect(file_exists($pkgDir . '/composer.json'))->toBeTrue();
    expect(file_exists($pkgDir . '/README.md'))->toBeTrue();
    expect(file_exists($pkgDir . '/src/TestToolkitToolkit.php'))->toBeTrue();
});

testWithFixture('create generates valid composer.json', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'my-api',
        'description' => 'My API toolkit',
    ]);

    $composerPath = $fixture->tmpDir . '/packages/my-api/composer.json';
    $data = json_decode((string) file_get_contents($composerPath), true);

    expect($data)->toBeArray();
    expect($data['name'])->toBe('coquibot/coqui-toolkit-my-api');
    expect($data['description'])->toBe('My API toolkit');
    expect($data['autoload']['psr-4'])->toHaveKey('CoquiBot\\Toolkits\\MyApi\\');
    expect($data['extra']['php-agents']['toolkits'])->toBe(['CoquiBot\\Toolkits\\MyApi\\MyApiToolkit']);
    expect($data['require']['php'])->toBe('^8.4');
    expect($data['require']['carmelosantana/php-agents'])->toBe('^0.13');
});

testWithFixture('create includes dependencies in composer.json', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'dep-test',
        'description' => 'Dependency test',
        'dependencies' => 'guzzlehttp/guzzle:^7.0,symfony/http-client:^7.0',
    ]);

    $composerPath = $fixture->tmpDir . '/packages/dep-test/composer.json';
    $data = json_decode((string) file_get_contents($composerPath), true);

    expect($data['require'])->toHaveKey('guzzlehttp/guzzle');
    expect($data['require']['guzzlehttp/guzzle'])->toBe('^7.0');
    expect($data['require'])->toHaveKey('symfony/http-client');
    expect($data['require']['symfony/http-client'])->toBe('^7.0');
});

testWithFixture('create includes credentials in composer.json', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'cred-test',
        'description' => 'Credential test',
        'credentials' => '{"MY_API_KEY": "API key from https://example.com"}',
    ]);

    $composerPath = $fixture->tmpDir . '/packages/cred-test/composer.json';
    $data = json_decode((string) file_get_contents($composerPath), true);

    expect($data['extra']['php-agents']['credentials'])->toBe([
        'MY_API_KEY' => 'API key from https://example.com',
    ]);
});

testWithFixture('create accepts native credential map', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'cred-map-test',
        'description' => 'Credential map test',
        'credentials' => ['MAP_API_KEY' => 'API key from native map'],
    ]);

    $composerPath = $fixture->tmpDir . '/packages/cred-map-test/composer.json';
    $data = json_decode((string) file_get_contents($composerPath), true);

    expect($data['extra']['php-agents']['credentials'])->toBe([
        'MAP_API_KEY' => 'API key from native map',
    ]);
});

testWithFixture('create generates toolkit class with credentials support', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'cred-toolkit',
        'description' => 'Toolkit with credentials',
        'credentials' => '{"CRED_API_KEY": "The API key"}',
    ]);

    $classPath = $fixture->tmpDir . '/packages/cred-toolkit/src/CredToolkitToolkit.php';
    $content = (string) file_get_contents($classPath);

    expect($content)->toContain('fromEnv()');
    expect($content)->toContain('resolveApiKey()');
    expect($content)->toContain('CRED_API_KEY');
    expect($content)->toContain('declare(strict_types=1)');
    expect($content)->toContain('namespace CoquiBot\\Toolkits\\CredToolkit');
    expect($content)->toContain('implements ToolkitInterface');
});

testWithFixture('create generates valid PHP syntax', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'syntax-check',
        'description' => 'Syntax check test',
    ]);

    $classPath = $fixture->tmpDir . '/packages/syntax-check/src/SyntaxCheckToolkit.php';

    $output = [];
    $returnCode = 0;
    exec('php -l ' . escapeshellarg($classPath) . ' 2>&1', $output, $returnCode);

    expect($returnCode)->toBe(0);
});

testWithFixture('create uses custom namespace when provided', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'custom-ns',
        'description' => 'Custom namespace test',
        'namespace' => 'Acme\\CustomToolkit',
    ]);

    $composerPath = $fixture->tmpDir . '/packages/custom-ns/composer.json';
    $data = json_decode((string) file_get_contents($composerPath), true);

    expect($data['autoload']['psr-4'])->toHaveKey('Acme\\CustomToolkit\\');
    expect($data['extra']['php-agents']['toolkits'][0])->toBe('Acme\\CustomToolkit\\CustomToolkitToolkit');
});

testWithFixture('create rejects duplicate package', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_create');
    $tool->execute(['name' => 'dupe-test', 'description' => 'First']);
    $result = $tool->execute(['name' => 'dupe-test', 'description' => 'Second']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('already exists');
});

testWithFixture('create sanitizes package name', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'My Special_Toolkit!!!',
        'description' => 'Name sanitization test',
    ]);

    $pkgDir = $fixture->tmpDir . '/packages/my-special-toolkit';
    expect(is_dir($pkgDir))->toBeTrue();
});

testWithFixture('create strips coquibot prefix from name', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'coquibot/strip-prefix',
        'description' => 'Prefix strip test',
    ]);

    $pkgDir = $fixture->tmpDir . '/packages/strip-prefix';
    expect(is_dir($pkgDir))->toBeTrue();
});

testWithFixture('create generates README with credential docs', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'readme-cred',
        'description' => 'README credential test',
        'credentials' => '{"README_KEY": "Key for readme test"}',
    ]);

    $readmePath = $fixture->tmpDir . '/packages/readme-cred/README.md';
    $content = (string) file_get_contents($readmePath);

    expect($content)->toContain('README_KEY');
    expect($content)->toContain('Key for readme test');
    expect($content)->toContain('Configuration');
});

testWithFixture('add_tool rejects empty toolkit name', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_add');
    $result = $tool->execute([
        'toolkit_name' => '',
        'tool_name' => 'test',
        'tool_description' => 'Test tool',
    ]);

    expect($result->status->value)->toBe('error');
});

testWithFixture('add_tool rejects invalid snake_case name', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_add');
    $createTool = findTool($fixture->tools, 'coqui_toolkit_create');
    $createTool->execute(['name' => 'add-test', 'description' => 'Test']);

    $result = $tool->execute([
        'toolkit_name' => 'add-test',
        'tool_name' => 'InvalidName',
        'tool_description' => 'Test tool',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('snake_case');
});

testWithFixture('add_tool rejects non-existent toolkit', function (ToolkitFixture $fixture): void {
    $tool = findTool($fixture->tools, 'coqui_toolkit_add');
    $result = $tool->execute([
        'toolkit_name' => 'nonexistent',
        'tool_name' => 'test',
        'tool_description' => 'Test tool',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('not found');
});

testWithFixture('add_tool inserts new tool into toolkit', function (ToolkitFixture $fixture): void {
    $createTool = findTool($fixture->tools, 'coqui_toolkit_create');
    $createTool->execute(['name' => 'addable', 'description' => 'Addable toolkit']);

    $addTool = findTool($fixture->tools, 'coqui_toolkit_add');
    $result = $addTool->execute([
        'toolkit_name' => 'addable',
        'tool_name' => 'fetch_data',
        'tool_description' => 'Fetches data from an API',
        'parameters' => '[{"name": "url", "type": "string", "description": "The URL to fetch", "required": true}]',
    ]);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('fetch_data');

    $classPath = $fixture->tmpDir . '/packages/addable/src/AddableToolkit.php';
    $content = (string) file_get_contents($classPath);

    expect($content)->toContain("'fetch_data'");
    expect($content)->toContain('fetchDataTool');
    expect($content)->toContain('The URL to fetch');
});

testWithFixture('add_tool generates valid PHP after insertion', function (ToolkitFixture $fixture): void {
    $createTool = findTool($fixture->tools, 'coqui_toolkit_create');
    $createTool->execute(['name' => 'valid-add', 'description' => 'Valid add test']);

    $addTool = findTool($fixture->tools, 'coqui_toolkit_add');
    $addTool->execute([
        'toolkit_name' => 'valid-add',
        'tool_name' => 'my_action',
        'tool_description' => 'Does something',
    ]);

    $classPath = $fixture->tmpDir . '/packages/valid-add/src/ValidAddToolkit.php';

    $output = [];
    $returnCode = 0;
    exec('php -l ' . escapeshellarg($classPath) . ' 2>&1', $output, $returnCode);

    expect($returnCode)->toBe(0);
});

testWithFixture('add_tool rejects duplicate tool name', function (ToolkitFixture $fixture): void {
    $createTool = findTool($fixture->tools, 'coqui_toolkit_create');
    $createTool->execute(['name' => 'dup-tool', 'description' => 'Dup test']);

    $addTool = findTool($fixture->tools, 'coqui_toolkit_add');
    $addTool->execute([
        'toolkit_name' => 'dup-tool',
        'tool_name' => 'my_tool',
        'tool_description' => 'First tool',
    ]);

    $result = $addTool->execute([
        'toolkit_name' => 'dup-tool',
        'tool_name' => 'my_tool',
        'tool_description' => 'Duplicate tool',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('already exists');
});

testWithFixture('add_tool supports multiple parameter types', function (ToolkitFixture $fixture): void {
    $createTool = findTool($fixture->tools, 'coqui_toolkit_create');
    $createTool->execute(['name' => 'multi-param', 'description' => 'Multi param test']);

    $addTool = findTool($fixture->tools, 'coqui_toolkit_add');
    $result = $addTool->execute([
        'toolkit_name' => 'multi-param',
        'tool_name' => 'complex_tool',
        'tool_description' => 'A complex tool',
        'parameters' => json_encode([
            ['name' => 'query', 'type' => 'string', 'description' => 'Search query', 'required' => true],
            ['name' => 'count', 'type' => 'number', 'description' => 'Result count', 'required' => false],
            ['name' => 'verbose', 'type' => 'bool', 'description' => 'Verbose output', 'required' => false],
            ['name' => 'format', 'type' => 'enum', 'description' => 'Output format', 'required' => true, 'values' => ['json', 'text', 'csv']],
        ], JSON_THROW_ON_ERROR),
    ]);

    expect($result->status->value)->toBe('success');

    $classPath = $fixture->tmpDir . '/packages/multi-param/src/MultiParamToolkit.php';
    $content = (string) file_get_contents($classPath);

    expect($content)->toContain('StringParameter');
    expect($content)->toContain('NumberParameter');
    expect($content)->toContain('BoolParameter');
    expect($content)->toContain('EnumParameter');
});

testWithFixture('add_tool accepts native parameter definitions', function (ToolkitFixture $fixture): void {
    $createTool = findTool($fixture->tools, 'coqui_toolkit_create');
    $createTool->execute(['name' => 'native-param', 'description' => 'Native param test']);

    $addTool = findTool($fixture->tools, 'coqui_toolkit_add');
    $result = $addTool->execute([
        'toolkit_name' => 'native-param',
        'tool_name' => 'typed_tool',
        'tool_description' => 'Uses native parameter definitions',
        'parameters' => [
            ['name' => 'query', 'type' => 'string', 'description' => 'Search query', 'required' => true],
            ['name' => 'verbose', 'type' => 'bool', 'description' => 'Verbose output', 'required' => false],
        ],
    ]);

    expect($result->status->value)->toBe('success');

    $classPath = $fixture->tmpDir . '/packages/native-param/src/NativeParamToolkit.php';
    $content = (string) file_get_contents($classPath);

    expect($content)->toContain('typed_tool');
    expect($content)->toContain('Search query');
    expect($content)->toContain('BoolParameter');
});

testWithFixture('all tools produce valid function schemas', function (ToolkitFixture $fixture): void {
    foreach ($fixture->tools as $tool) {
        $schema = $tool->toFunctionSchema();

        expect($schema)->toBeArray();
        expect($schema['type'])->toBe('function');
        expect($schema['function'])->toBeArray();
        expect($schema['function']['name'])->toBe($tool->name());
        expect($schema['function']['parameters'])->toBeArray();
        expect($schema['function']['parameters']['type'])->toBe('object');
    }
});
