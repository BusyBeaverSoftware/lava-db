<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Problem;

use Lava\Db\Problem\DbConnectionFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one problem in this pack that reports a credential, asserted on the
 * credential rather than on the prose around it.
 *
 * `DbConnectionFailed` is raised from exactly one place — the `catch` around
 * `new \PDO(...)` in {@see \Lava\Db\Connection::pdo()} — and its report is
 * written to a terminal, a log file, or CI output. So a leak here is not a
 * cosmetic bug in an error message: it is a password in a build log. That makes
 * the redaction the part of this class worth a test of its own, and it is why
 * the cases below assert on the ABSENCE of the secret as well as on the shape
 * of the report.
 *
 * A `\PDOException` is constructed directly rather than provoked, on purpose:
 * the subject is the redaction of whatever text arrives, and a real driver's
 * wording differs by driver, by version, and by whether the server is up. A
 * test that needed a live MySQL to check a `preg_replace` would be a test that
 * skips on most machines.
 */
final class DbConnectionFailedTest extends TestCase
{
    private const SECRET = 'hunter2';

    public function testTheSchemeIsNamedInTheMessageAndTheContext(): void
    {
        $problem = DbConnectionFailed::of(
            'postgresql:host=db;dbname=app',
            new \PDOException('SQLSTATE[08006] connection refused'),
        );

        self::assertStringContainsString('postgresql', $problem->getMessage());
        self::assertSame('postgresql', $problem->context['scheme']);
        self::assertSame('db_connection_failed', $problem->code());
    }

    /**
     * A DSN with no scheme is still reportable — the message just has no name
     * to put in it. `strstr($dsn, ':', true)` returns false here, and a report
     * reading "Could not connect to the  database" is ugly but honest; a crash
     * on the way to a diagnosis would not be.
     */
    public function testADsnWithoutASchemeStillProducesAReport(): void
    {
        $problem = DbConnectionFailed::of('host=db;dbname=app', new \PDOException('nope'));

        self::assertSame('', $problem->context['scheme']);
        self::assertStringContainsString('nope', $problem->getMessage());
    }

    /**
     * The docblock's first claim: a `password=…` in the DSN never reaches the
     * report — neither in the message nor in the `dsn` the context carries.
     */
    public function testAPasswordInTheDsnIsMaskedInEveryField(): void
    {
        $problem = DbConnectionFailed::of(
            'mysql:host=db;dbname=app;password=' . self::SECRET,
            new \PDOException('SQLSTATE[HY000] [1045] Access denied'),
        );

        self::assertStringNotContainsString(self::SECRET, $problem->getMessage());
        self::assertStringNotContainsString(self::SECRET, (string) $problem->context['dsn']);
        self::assertSame('mysql:host=db;dbname=app;password=***', $problem->context['dsn']);
    }

    /**
     * The docblock's second half, and the reason it is a posture rather than a
     * response: the driver's message is not ours, so a driver that echoes the
     * connection string — or the credential it was handed — must not be trusted
     * to have left it out.
     *
     * The message here is synthetic, because no driver installed in this
     * repository produces one: pdo_sqlite says `unable to open database file`
     * and an unusable scheme says `could not find driver`, and neither quotes
     * the DSN. That is the point of the test. What it pins down is that the
     * redaction covers the message FIELD, so the next driver that is chatty
     * does not get a free pass — this is the case that fails if the redaction
     * is ever applied only to the DSN.
     */
    public function testAPasswordInsideTheDriversMessageIsMasked(): void
    {
        $problem = DbConnectionFailed::of(
            'mysql:host=db;dbname=app',
            new \PDOException('SQLSTATE[HY000] [1045] Access denied for user (using password=' . self::SECRET . ')'),
        );

        self::assertStringNotContainsString(self::SECRET, $problem->getMessage());
        self::assertStringContainsString('password=***', $problem->getMessage());
        // Masking the credential must not swallow the diagnosis around it.
        self::assertStringContainsString('Access denied for user', $problem->getMessage());
    }

    /**
     * The `DATABASE_URL` shape. There is no `password=` key in it, so a
     * key/value match alone leaves the credential in place — and the DSN is not
     * a message the driver chose to print, it is a field this problem puts in
     * its own `context` on every failure. So this shape leaks by construction,
     * not by a driver being chatty.
     *
     * @return iterable<string, array{string}>
     */
    public static function urlShapedTexts(): iterable
    {
        yield 'a DSN in URL form' => ['mysql://app:' . self::SECRET . '@db.internal:3306/app'];

        yield 'a URL with no user' => ['mysql://:' . self::SECRET . '@db.internal/app'];

        yield 'an untrusted message containing a URL' => ['failed to open mysql://app:' . self::SECRET . '@db.internal/app'];

        yield 'a URL with no port or path' => ['postgres://app:' . self::SECRET . '@db.internal'];
    }

    #[DataProvider('urlShapedTexts')]
    public function testAUrlShapedCredentialIsMaskedToo(string $text): void
    {
        $redacted = DbConnectionFailed::redact($text);

        self::assertStringNotContainsString(self::SECRET, $redacted);
        self::assertStringContainsString('***', $redacted);
        // The host survives, because "could not connect" without a host is a
        // redaction that cost more than it bought.
        self::assertStringContainsString('db.internal', $redacted);
    }

    /**
     * The counterpart to every absence assertion above: redaction has to leave
     * an ordinary DSN alone, or "no credential in the report" would be satisfied
     * by a function that redacts everything. A test suite made only of
     * `assertStringNotContainsString` passes when the subject is broken in the
     * other direction.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function harmlessTexts(): iterable
    {
        yield 'a DSN with no credential' => ['sqlite:/var/lib/app.sqlite', 'sqlite:/var/lib/app.sqlite'];

        yield 'a host:port with no userinfo' => ['mysql:host=db:3306;dbname=app', 'mysql:host=db:3306;dbname=app'];

        yield 'an @ in a message, not a URL' => ['Access denied for user app@db', 'Access denied for user app@db'];
    }

    #[DataProvider('harmlessTexts')]
    public function testRedactionLeavesTextWithoutACredentialAlone(string $text, string $expected): void
    {
        self::assertSame($expected, DbConnectionFailed::redact($text));
    }

    /**
     * The report is a `LavaProblem` like any other, so the shape an agent
     * parses is the framework-wide one — and the underlying driver exception
     * stays chained, because a `--verbose` path that drops it would lose the
     * only text that says which driver refused.
     */
    public function testTheReportKeepsTheFrameworkShapeAndChainsTheDriverError(): void
    {
        $previous = new \PDOException('SQLSTATE[HY000] [2002] Connection refused');

        $problem = DbConnectionFailed::of('mysql:host=db;dbname=app', $previous);
        $json = $problem->json();

        self::assertSame('db_connection_failed', $json['code']);
        self::assertSame('fatal', $json['severity']);
        self::assertSame(500, $problem->httpStatus());
        self::assertNull($json['source']);
        self::assertSame(['scheme', 'dsn'], array_keys($json['context']));
        self::assertSame($previous, $problem->getPrevious());
        // The fix names where the credentials come from, which is the part an
        // agent cannot guess from the driver's error.
        self::assertStringContainsString('DATABASE_USER/DATABASE_PASSWORD', $problem->fix);
    }
}
