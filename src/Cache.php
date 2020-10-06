<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Origin: https://github.com/symfony/flex/blob/master/src/Cache.php
 */

namespace Rubenrua\SymfonyCleanTagsComposerPlugin;

use Composer\Cache as BaseCache;
use Composer\IO\IOInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
class Cache extends BaseCache
{
    private $versionParser;
    private $symfonyRequire;
    private $symfonyConstraints;
    private $io;

    public function setSymfonyRequire($symfonyRequire, IOInterface $io = null)
    {
        $this->versionParser = new VersionParser();
        $this->symfonyRequire = $symfonyRequire;
        $this->symfonyConstraints = $this->versionParser->parseConstraints($symfonyRequire);
        $this->io = $io;
    }

    public function read($file)
    {
        $content = parent::read($file);

        if (0 === strpos($file, 'provider-symfony$') && \is_array($data = json_decode($content, true))) {
            $content = json_encode($this->removeLegacyTags($data));
        }

        return $content;
    }

    public function removeLegacyTags(array $data)
    {
        if (!$this->symfonyConstraints || !isset($data['packages']['symfony/symfony'])) {
            return $data;
        }

        $symfonyPackages = [];
        $symfonySymfony = $data['packages']['symfony/symfony'];

        foreach ($symfonySymfony as $version => $composerJson) {
            if (null !== $alias = (isset($composerJson['extra']['branch-alias'][$version]) ? $composerJson['extra']['branch-alias'][$version] : null)) {
                $normalizedVersion = $this->versionParser->normalize($alias);
            } elseif (null === $normalizedVersion = isset($composerJson['version_normalized']) ? $composerJson['version_normalized'] : null) {
                continue;
            }

            if ($this->symfonyConstraints->matches(new Constraint('==', $normalizedVersion))) {
                $symfonyPackages += $composerJson['replace'];
            } else {
                if (null !== $this->io) {
                    $this->io->writeError(sprintf('<info>Restricting packages listed in "symfony/symfony" to "%s"</info>', $this->symfonyRequire));
                    $this->io = null;
                }
                unset($symfonySymfony[$version]);
            }
        }

        if (!$symfonySymfony) {
            // ignore requirements: their intersection with versions of symfony/symfony is empty
            return $data;
        }

        $data['packages']['symfony/symfony'] = $symfonySymfony;
        unset($symfonySymfony['dev-master']);

        foreach ($data['packages'] as $name => $versions) {
            $devMasterAlias = isset($versions['dev-master']['extra']['branch-alias']['dev-master']) ?
                $versions['dev-master']['extra']['branch-alias']['dev-master'] :
                null;
            if (!isset($symfonyPackages[$name]) || null === $devMasterAlias) {
                continue;
            }
            $devMaster = $versions['dev-master'];
            $versions = array_intersect_key($versions, $symfonySymfony);

            if ($this->symfonyConstraints->matches(new Constraint('==', $this->versionParser->normalize($devMasterAlias)))) {
                $versions['dev-master'] = $devMaster;
            }

            if ($versions) {
                $data['packages'][$name] = $versions;
            }
        }

        return $data;
    }
}
