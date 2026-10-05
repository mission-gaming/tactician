<?php

declare(strict_types=1);

// The restricted-terms CI job runs .github/scripts/check-restricted-terms.sh
// with a secret expression. The job's whole purpose is to keep certain terms
// out of a public repository, so the script must never print the expression
// or the text it matched, must not be bypassed by awkward files, and must
// fail loudly rather than pass when the expression is unusable. These tests
// drive the real script against throwaway repositories using a stand-in
// expression.
//
// Not covered here, because it needs the hosted runner: that the workflow
// passes the secret through, and that the runner renders the annotations.

const RESTRICTED_TERMS_SCRIPT = __DIR__ . '/../../.github/scripts/check-restricted-terms.sh';

/**
 * Builds a throwaway repository with the given tracked files, runs the check
 * in it, and returns the exit code with everything the script printed.
 *
 * @param array<string, string> $files path => content
 * @param array<string, string> $symlinks path => target
 * @param null|string $terms the expression, or null to leave it unset
 *
 * @throws RuntimeException when the repository or the process cannot be set up
 *
 * @return array{exitCode: int, output: string}
 */
function runRestrictedTermsCheck(array $files, ?string $terms, array $symlinks = [], bool $track = true): array
{
    $repository = sys_get_temp_dir() . '/tactician-restricted-terms-' . uniqid('', true);
    mkdir($repository, 0o777, true);

    try {
        foreach ($files as $path => $content) {
            $directory = dirname($repository . '/' . $path);
            if (!is_dir($directory)) {
                mkdir($directory, 0o777, true);
            }
            file_put_contents($repository . '/' . $path, $content);
        }
        foreach ($symlinks as $path => $target) {
            symlink($target, $repository . '/' . $path);
        }

        $setup = 'git init --quiet .' . ($track ? ' && git add --all' : '');
        exec(sprintf('cd %s && %s 2>&1', escapeshellarg($repository), $setup), $setupOutput, $setupExitCode);
        if ($setupExitCode !== 0) {
            throw new RuntimeException("Could not prepare the repository:\n" . implode("\n", $setupOutput));
        }

        $environment = getenv();
        unset($environment['RESTRICTED_TERMS'], $environment['GIT_DIR'], $environment['GIT_WORK_TREE']);
        if ($terms !== null) {
            $environment['RESTRICTED_TERMS'] = $terms;
        }

        $process = proc_open(
            ['bash', RESTRICTED_TERMS_SCRIPT],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repository,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the restricted terms check.');
        }

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exitCode' => proc_close($process), 'output' => $output];
    } finally {
        exec('rm -rf ' . escapeshellarg($repository));
    }
}

describe('restricted terms check', function (): void {
    it('passes a repository that mentions no restricted term', function (): void {
        $result = runRestrictedTermsCheck(['README.md' => "A scheduling library.\n"], 'zebra|quagga');

        expect($result['exitCode'])->toBe(0)
            ->and($result['output'])->toBe('');
    });

    it('fails and names the file that mentions a restricted term', function (): void {
        $result = runRestrictedTermsCheck([
            'README.md' => "A scheduling library.\n",
            'docs/notes.md' => "Built for the zebra platform.\n",
        ], 'zebra|quagga');

        expect($result['exitCode'])->toBe(1)
            ->and($result['output'])->toBe("::error file=docs/notes.md::Restricted term found in docs/notes.md\n");
    });

    it('never prints the matched text or the expression', function (): void {
        $result = runRestrictedTermsCheck(
            ['notes.md' => "secret codename Zebra-42 appears here\n"],
            'zebra-[0-9]+|quagga'
        );

        expect($result['exitCode'])->toBe(1)
            ->and($result['output'])->not->toContain('Zebra-42')
            ->and($result['output'])->not->toContain('codename')
            ->and($result['output'])->not->toContain('zebra')
            ->and($result['output'])->not->toContain('quagga');
    });

    it('matches regardless of case', function (string $content): void {
        $result = runRestrictedTermsCheck(['notes.md' => $content], 'zebra');

        expect($result['exitCode'])->toBe(1);
    })->with([
        'upper' => ["ZEBRA\n"],
        'mixed' => ["zEbRa\n"],
        'embedded in a word' => ["thezebras\n"],
    ]);

    it('matches an upper-case expression against lower-case text', function (): void {
        $result = runRestrictedTermsCheck(['notes.md' => "zebra\n"], 'ZEBRA');

        expect($result['exitCode'])->toBe(1);
    });

    it('finds a term in a file that git treats as binary', function (): void {
        $result = runRestrictedTermsCheck(['image.dat' => "\x00\x01\x02 zebra \x00\n"], 'zebra');

        expect($result['exitCode'])->toBe(1)
            ->and($result['output'])->toContain('file=image.dat::');
    });

    it('finds a term in a file marked binary by attributes', function (): void {
        $result = runRestrictedTermsCheck([
            '.gitattributes' => "*.md binary\n",
            'notes.md' => "zebra\n",
        ], 'zebra');

        expect($result['exitCode'])->toBe(1)
            ->and($result['output'])->toContain('file=notes.md::');
    });

    it('finds a term in the target of a symbolic link', function (): void {
        $result = runRestrictedTermsCheck(
            ['README.md' => "clean\n"],
            'zebra',
            ['shortcut' => '/srv/zebra/config']
        );

        expect($result['exitCode'])->toBe(1)
            ->and($result['output'])->toBe("::error file=shortcut::Restricted term found in shortcut\n");
    });

    it('ignores files that are not tracked', function (): void {
        $result = runRestrictedTermsCheck(['scratch.md' => "zebra\n"], 'zebra', track: false);

        expect($result['exitCode'])->toBe(0);
    });

    it('reports every offending file', function (): void {
        $result = runRestrictedTermsCheck([
            'a.md' => "zebra\n",
            'b.md' => "clean\n",
            'nested/deep/c.md' => "quagga\n",
        ], 'zebra|quagga');

        expect($result['exitCode'])->toBe(1)
            ->and($result['output'])->toContain('file=a.md::')
            ->and($result['output'])->toContain('file=nested/deep/c.md::')
            ->and($result['output'])->not->toContain('b.md');
    });

    describe('paths', function (): void {
        it('withholds a path that itself contains a term', function (): void {
            $result = runRestrictedTermsCheck(['docs/Zebra-plan.md' => "clean\n"], 'zebra');

            expect($result['exitCode'])->toBe(1)
                ->and($result['output'])->not->toContain('Zebra')
                ->and($result['output'])->toContain('path of 1 tracked file(s)');
        });

        it('withholds a matching path even when the content matches too', function (): void {
            $result = runRestrictedTermsCheck([
                'zebra/notes.md' => "zebra\n",
                'other.md' => "zebra\n",
            ], 'zebra');

            expect($result['exitCode'])->toBe(1)
                ->and($result['output'])->toContain('file=other.md::')
                ->and($result['output'])->toContain('path of 1 tracked file(s)')
                ->and($result['output'])->not->toContain('zebra');
        });

        it('withholds a symbolic link whose own name contains a term', function (): void {
            $result = runRestrictedTermsCheck(
                ['README.md' => "clean\n"],
                'zebra',
                ['zebra-link' => '/srv/zebra']
            );

            expect($result['exitCode'])->toBe(1)
                ->and($result['output'])->not->toContain('zebra');
        });

        it('names a file whose path contains spaces', function (): void {
            $result = runRestrictedTermsCheck(['my notes/a file.md' => "zebra\n"], 'zebra');

            expect($result['exitCode'])->toBe(1)
                ->and($result['output'])
                ->toBe("::error file=my notes/a file.md::Restricted term found in my notes/a file.md\n");
        });

        it('keeps leading and trailing spaces of a path', function (): void {
            $result = runRestrictedTermsCheck([' padded ' => "zebra\n"], 'zebra');

            expect($result['exitCode'])->toBe(1)
                ->and($result['output'])->toContain('::error file= padded ::');
        });

        it('names a file whose path is not ASCII or contains quotes and backslashes', function (string $path): void {
            $result = runRestrictedTermsCheck([$path => "zebra\n"], 'zebra');

            expect($result['exitCode'])->toBe(1)
                ->and($result['output'])->toContain("::Restricted term found in {$path}\n");
        })->with([
            'accented' => ['café.md'],
            'double quote' => ['say "hi".md'],
            'backslash' => ['back\slash.md'],
            'glob characters' => ['[a]*?.md'],
            'leading dash' => ['-rf.md'],
            'dollar and backtick' => ['$(id)`id`.md'],
        ]);

        it('escapes a path that tries to inject a workflow command', function (): void {
            $path = "evil\n::error file=x::injected %0A,title=spoof::tail.md";
            $result = runRestrictedTermsCheck([$path => "zebra\n"], 'zebra');

            expect($result['exitCode'])->toBe(1);

            // One annotation on one line: nothing in the path starts a command
            // of its own, and nothing in it survives as raw property syntax.
            $lines = explode("\n", rtrim($result['output'], "\n"));
            expect($lines)->toHaveCount(1)
                ->and($lines[0])->toStartWith('::error file=evil%0A%3A%3Aerror file=x%3A%3Ainjected %250A%2Ctitle=spoof%3A%3Atail.md::')
                ->and($lines[0])->toEndWith('::Restricted term found in evil%0A::error file=x::injected %250A,title=spoof::tail.md');
        });

        it('escapes a carriage return in a path', function (): void {
            $result = runRestrictedTermsCheck(["a\rb.md" => "zebra\n"], 'zebra');

            expect($result['exitCode'])->toBe(1)
                ->and($result['output'])->toBe("::error file=a%0Db.md::Restricted term found in a%0Db.md\n");
        });

        it('finds a term spelled out in a path that contains a newline', function (): void {
            $result = runRestrictedTermsCheck(["zebra\nfile.md" => "clean\n"], 'zebra');

            expect($result['exitCode'])->toBe(1)
                ->and($result['output'])->not->toContain('zebra');
        });
    });

    describe('expression', function (): void {
        it('skips with a notice when no expression is configured', function (?string $terms): void {
            $result = runRestrictedTermsCheck(['notes.md' => "zebra\n"], $terms);

            expect($result['exitCode'])->toBe(0)
                ->and($result['output'])->toBe("::notice::No restricted terms configured; skipping.\n");
        })->with([
            'unset' => [null],
            'empty' => [''],
            'newline only' => ["\n"],
            'blank lines and spaces' => [" \n\t\r\n\n"],
        ]);

        it('treats each line of a multi-line expression as an alternative', function (string $content): void {
            $result = runRestrictedTermsCheck(['notes.md' => $content], "zebra\nquagga\nokapi-[0-9]+");

            expect($result['exitCode'])->toBe(1);
        })->with([
            'first line' => ["a zebra\n"],
            'middle line' => ["a quagga\n"],
            'last line' => ["okapi-7\n"],
        ]);

        it('does not flag clean files when the expression has a trailing newline or blank lines', function (): void {
            $result = runRestrictedTermsCheck(['notes.md' => "clean\n\nmore\n"], "zebra\n\nquagga\n\n");

            expect($result['exitCode'])->toBe(0)
                ->and($result['output'])->toBe('');
        });

        it('still matches when the expression has Windows line endings', function (string $content): void {
            $result = runRestrictedTermsCheck(['notes.md' => $content], "zebra\r\nquagga\r\n");

            expect($result['exitCode'])->toBe(1);
        })->with([
            'first line' => ["a zebra here\n"],
            'last line' => ["a quagga here\n"],
        ]);

        it('fails without echoing an expression that is not valid', function (string $terms): void {
            $result = runRestrictedTermsCheck(['notes.md' => "clean\n"], $terms);

            expect($result['exitCode'])->toBe(2)
                ->and($result['output'])
                ->toBe("::error::RESTRICTED_TERMS is not a valid extended regular expression. Fix the secret.\n");
        })->with([
            'unbalanced parenthesis' => ['zebra(quagga'],
            'unbalanced bracket' => ['zebra[quagga'],
            'one bad line among good ones' => ["zebra\nqua(gga"],
        ]);

        it('fails without echoing an expression that matches every line', function (string $terms): void {
            $result = runRestrictedTermsCheck(['notes.md' => "clean\n"], $terms);

            expect($result['exitCode'])->toBe(2)
                ->and($result['output'])->toContain('matches an empty line')
                ->and($result['output'])->not->toContain('zebra');
        })->with([
            'optional term' => ['(zebra)?'],
            'starred term' => ['(zebra)*'],
            'bare anchors' => ['^$'],
        ]);

        it('treats regular expression syntax as syntax, not literal text', function (): void {
            $files = ['notes.md' => "build zebra-123 shipped\n", 'other.md' => "zebra-abc\n"];
            $result = runRestrictedTermsCheck($files, 'zebra-[0-9]{3}');

            expect($result['exitCode'])->toBe(1)
                ->and($result['output'])->toContain('file=notes.md::')
                ->and($result['output'])->not->toContain('other.md');
        });

        it('does not let the expression be read as a command-line option', function (): void {
            $result = runRestrictedTermsCheck(['notes.md' => "use --files-with-matches\n"], '--files-with-matches');

            expect($result['exitCode'])->toBe(1)
                ->and($result['output'])->toContain('file=notes.md::');
        });
    });

    it('fails outside a git work tree instead of passing', function (): void {
        $directory = sys_get_temp_dir() . '/tactician-restricted-terms-' . uniqid('', true);
        mkdir($directory, 0o777, true);

        try {
            $command = sprintf(
                'cd %s && GIT_CEILING_DIRECTORIES=%s RESTRICTED_TERMS=zebra bash %s 2>&1',
                escapeshellarg($directory),
                escapeshellarg(dirname($directory)),
                escapeshellarg(RESTRICTED_TERMS_SCRIPT)
            );
            exec($command, $outputLines, $exitCode);

            expect($exitCode)->toBe(2)
                ->and(implode("\n", $outputLines))->toContain('must run inside a git work tree');
        } finally {
            rmdir($directory);
        }
    });
});

describe('restricted terms workflow job', function (): void {
    $workflow = (string) file_get_contents(__DIR__ . '/../../.github/workflows/ci.yml');

    it('runs the tested script with the secret in the environment', function () use ($workflow): void {
        expect($workflow)
            ->toContain('RESTRICTED_TERMS: ${{ secrets.RESTRICTED_TERMS }}')
            ->toContain('run: bash .github/scripts/check-restricted-terms.sh');
    });

    it('never interpolates the secret into the script text', function () use ($workflow): void {
        // Only the env mapping may reference the secret: an expression expanded
        // inside `run:` would be pasted into the shell source verbatim.
        expect(substr_count($workflow, 'secrets.RESTRICTED_TERMS'))->toBe(1);
    });
});
