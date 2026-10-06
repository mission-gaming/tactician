<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

/**
 * One piece of PHP found in a Markdown document: a fenced `php` block, or
 * the hidden code of a `<!-- snippet: setup ... -->` comment.
 *
 * The mode says what the harness does with it (see
 * DocumentationSnippets for the rules):
 *
 * - RUN: executed, and must finish without any PHP diagnostic.
 * - THROWS: executed, and must end in the exception named by the marker.
 * - SKIP: never executed; the marker's reason says why.
 * - SETUP: hidden code that is never tested by itself and runs ahead of
 *   the later blocks of its section.
 */
final readonly class DocumentationSnippet
{
    public const string RUN = 'run';

    public const string THROWS = 'throws';

    public const string SKIP = 'skip';

    public const string SETUP = 'setup';

    /**
     * @param string $file The document, as a path relative to the repository root
     * @param int $line 1-based line of the opening fence (or of the setup comment)
     * @param string $section Text of the level-1 or level-2 heading the block sits under; '' above the first one
     * @param list<string> $code The lines between the fences, without the fences
     * @param self::* $mode
     * @param string|null $reason Why a SKIP block is not executed
     * @param string|null $exception The fully qualified exception class a THROWS block must end in
     * @param int $sectionLine 1-based line of that heading, 0 above the first one. It is the section's
     *     identity: two headings may share their text, and their blocks must not build on each other
     */
    public function __construct(
        public string $file,
        public int $line,
        public string $section,
        public array $code,
        public string $mode = self::RUN,
        public ?string $reason = null,
        public ?string $exception = null,
        public int $sectionLine = 0,
    ) {
    }

    /**
     * Whether both blocks sit under the same heading of the same document.
     */
    public function inSameSectionAs(self $other): bool
    {
        return $this->file === $other->file && $this->sectionLine === $other->sectionLine;
    }

    /**
     * Where the block is, in the form a failure message and a dataset key use.
     */
    public function location(): string
    {
        return $this->file . ':' . $this->line;
    }

    /**
     * Whether the later blocks of the section run on top of this one.
     * A skipped block has no runnable code, and a block that ends in an
     * exception leaves nothing to continue from.
     */
    public function carriesForward(): bool
    {
        return $this->mode === self::RUN || $this->mode === self::SETUP;
    }

    /**
     * Whether the harness executes the block as a test of its own.
     */
    public function isExecuted(): bool
    {
        return $this->mode === self::RUN || $this->mode === self::THROWS;
    }
}
