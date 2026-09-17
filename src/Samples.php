<?php

declare(strict_types=1);

namespace SvgTest;

/**
 * The sample payloads offered in the UI, in the order they should appear.
 */
final class Samples
{
    private const CATALOGUE = [
        'remote-references' => [
            'title'       => 'Remote references',
            'description' => 'Remote url() fills, javascript: links and data: URIs in href attributes.',
        ],
        'script-injection' => [
            'title'       => 'Script injection',
            'description' => 'Inline scripts, event handlers and script-bearing animation elements.',
        ],
        'style-and-css' => [
            'title'       => 'CSS payloads',
            'description' => 'Remote @import, expression(), -moz-binding and remote url() in styles.',
        ],
        'foreign-object' => [
            'title'       => 'foreignObject',
            'description' => 'HTML smuggled inside foreignObject, plus embedded images.',
        ],
        'xml-entities' => [
            'title'       => 'XML entities',
            'description' => 'A DOCTYPE declaring external entities — the XXE shape.',
        ],
        'use-expansion' => [
            'title'       => 'use expansion',
            'description' => 'Recursively nested <use> elements, the billion laughs of SVG.',
        ],
        'kitchen-sink' => [
            'title'       => 'Kitchen sink',
            'description' => 'A large real-world file with a bit of everything in it.',
        ],
        'clean-logo' => [
            'title'       => 'Clean file',
            'description' => 'A legitimate SVG that should come back untouched.',
        ],
    ];

    /**
     * @return array<int,array<string,string>>
     */
    public function all(): array
    {
        $samples = [];

        foreach (self::CATALOGUE as $slug => $meta) {
            $path = SVGTEST_SAMPLES_DIR . '/' . $slug . '.svg';

            if (!is_readable($path)) {
                continue;
            }

            $samples[] = [
                'slug'        => $slug,
                'title'       => $meta['title'],
                'description' => $meta['description'],
                'svg'         => (string) file_get_contents($path),
            ];
        }

        return $samples;
    }

    public function default(): ?string
    {
        $path = SVGTEST_SAMPLES_DIR . '/remote-references.svg';

        return is_readable($path) ? (string) file_get_contents($path) : null;
    }
}
