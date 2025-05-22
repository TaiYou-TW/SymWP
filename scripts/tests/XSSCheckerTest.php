<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../XSSChecker.php';

class XSSCheckerTest extends TestCase
{
    private string $vulnerableHarnessPath;
    private string $safeHarnessPath;

    protected function setUp(): void
    {
        $this->vulnerableHarnessPath = __DIR__ . '/VulnerableXSSCheckerStub.php';
        $this->safeHarnessPath = __DIR__ . '/SafeXSSCheckerStub.php';
    }

    public function testVulnerableRun()
    {
        $taintChecker = new XSSChecker($this->vulnerableHarnessPath);
        $issues = $taintChecker->run();

        $this->assertIsArray($issues);
        $this->assertNotEmpty($issues);
        $this->assertEquals([
            "⚠️ Taint marker found in output",
            "🚨 Potential quotes breaks in tags detected: <input id=\"c\" value=\"__TAINTED_VAR_S__'\"&lt;&gt;/;=#`\&lt;h1&gt;a&lt;/h1&gt;&lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=x onerror=alert(1)&gt;__TAINTED_VAR_E__\" />\n",
            "🚨 Potential space breaks in tag without quotes detected: <input id=d value=__TAINTED_VAR_S__&#039;&quot;&lt;&gt;/;=#`\&lt;h1&gt;a&lt;/h1&gt;&lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=x onerror=alert(1)&gt;__TAINTED_VAR_E__ />\n",
            "🚨 Potential space breaks in tag without quotes detected: <input id=d value=__TAINTED_VAR_S__&#039;&quot;&lt;&gt;/;=#`\&lt;h1&gt;a&lt;/h1&gt;&lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=x onerror=alert(1)&gt;__TAINTED_VAR_E__ />\n",
            "🚨 Potential quotes breaks in tags detected: <img src=\"img/xxx.png\" alt=\"__TAINTED_VAR_S__'\"&lt;&gt;/;=#`\&lt;h1&gt;a&lt;/h1&gt;&lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=x onerror=alert(1)&gt;__TAINTED_VAR_E__\">\n",
            "🚨 Potential quotes breaks in tags detected: <img src=\"img/xxx.png\" alt=\"__TAINTED_VAR_S__'\"<>/;=#`\<h1>a</h1><script>alert(1)</script><img src=x onerror=alert(1)>__TAINTED_VAR_E__\">\n",
        ], $issues);
    }

    public function testSafeRun()
    {
        $taintChecker = new XSSChecker($this->safeHarnessPath);
        $issues = $taintChecker->run();

        $this->assertIsArray($issues);
        $this->assertNotEmpty($issues);
        $this->assertEquals([
            "⚠️ Taint marker found in output",
        ], $issues);
    }
}