<?php

declare(strict_types=1);

/**
 * Twig-CS-Fixer configuration.
 *
 * Two things need saying about applying a Twig coding standard here.
 *
 * FILE NAMES. Archipelago Metadata Displays are stored in a database, not on
 * disk. The files in twig/metadatadisplays are copies, and they are named
 * <name>.twig.html rather than Drupal's <name>.html.twig. Twig-CS-Fixer looks
 * for *.twig by default and finds nothing at all here, so the finder below is
 * load bearing -- without it the job reports "Files linted: 0" and passes.
 *
 * WHAT IT CAN AND CANNOT TELL YOU. This checks coding standards: spacing,
 * delimiter style, operator placement. It never executes a template, so it
 * cannot see that one emits invalid JSON. That is what tools/twig-render does.
 * Both are worth having; neither substitutes for the other.
 */

use TwigCsFixer\Config\Config;
use TwigCsFixer\File\Finder;
use TwigCsFixer\Ruleset\Ruleset;
use TwigCsFixer\Standard\TwigCsFixer as TwigCsFixerStandard;

$finder = (new Finder())
    ->in(__DIR__ . '/twig')
    ->name('*.twig.html');

$ruleset = new Ruleset();
$ruleset->addStandard(new TwigCsFixerStandard());

$config = new Config('archipelago-twig-templates');
$config->setFinder($finder);
$config->setRuleset($ruleset);

return $config;
