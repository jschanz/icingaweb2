<?php
/* Icinga Web 2 | (c) 2016 Icinga Development Team | GPLv2+ */

namespace Icinga\Module\Translation;

use Icinga\Application\Config;
use Icinga\Exception\IcingaException;

/**
 * Class Statistics
 *
 * Creates statistics about a .po file
 */
class Statistics
{
    /**
     * The statistics' path
     *
     * @var string
     */
    protected $path;

    /**
     * The amount of entries
     *
     * @var int
     */
    protected $entryCount;

    /**
     * The amount of untranslated entries
     *
     * @var int
     */
    protected $untranslatedEntryCount;

    /**
     * The amount of translated entries
     *
     * @var int
     */
    protected $translatedEntryCount;

    /**
     * The amount of fuzzy entries
     *
     * @var int
     */
    protected $fuzzyEntryCount;

    /**
     * The amount of faulty entries
     *
     * @var int
     */
    protected $faultyEntryCount;

    /**
     * Create a new Statistics object
     *
     * @param   string  $path   The path from which to create the statistics
     */
    public function __construct($path)
    {
        $this->path = $path;
    }

    public static function load($path)
    {
        $statistics = new static($path);
        $statistics->parseStatistics();

        return $statistics;
    }

    /**
     * Parse the gathered statistics from msgfmt of the gettext tools
     *
     * @throws  IcingaException     In case it's not possible to parse msgfmt's output
     */
    public function parseStatistics()
    {
        $info = explode('msgfmt: found ', $this->getRawStatistics());
        $relevant = end($info);
        if ($relevant === false) {
            throw new IcingaException('Cannot parse the output given by msgfmt for path %s', $this->path);
        }

        preg_match_all('/\d+ [a-z]+/', $relevant , $results);
        foreach ($results[0] as $value) {
            $chunks = explode(' ', $value);
            switch ($chunks[1]) {
                case 'fatal':
                    $this->faultyEntryCount = (int)$chunks[0];
                    break;
                case 'translated':
                    $this->translatedEntryCount = (int)$chunks[0];
                    break;
                case 'fuzzy':
                    $this->fuzzyEntryCount = (int)$chunks[0];
                    break;
                case 'untranslated':
                    $this->untranslatedEntryCount = (int)$chunks[0];
                    break;
            }
        }

        $this->entryCount = $this->faultyEntryCount
            + $this->translatedEntryCount
            + $this->fuzzyEntryCount
            + $this->untranslatedEntryCount;
    }

    /**
     * Run msgfmt from the gettext tools and output the gathered statistics
     *
     * @return string
     */
    protected function getRawStatistics()
    {
        // TODO (JeM): Make a get- and setConfig? Maybe use the translation helper
        $msgfmtPath = Config::module('translation')
            ->get('translation', 'msgfmt', '/usr/bin/env msgfmt');

        $line = $msgfmtPath . ' ' . $this->path . ' --statistics -cf';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $env = ['LANG' => 'en_GB'];
        $process = proc_open(
            $line,
            $descriptorSpec,
            $pipes,
            null,
            $env,
            null
        );

        $info = stream_get_contents($pipes[2]);

        proc_close($process);

        return $info;
    }

    /**
     * Count all Entries of these statistics
     *
     * @return int
     */
    public function getEntryCount()
    {
        return $this->entryCount;
    }

    /**
     * Count all untranslated entries of these statistics
     *
     * @return int
     */
    public function getUntranslatedEntryCount()
    {
        return $this->untranslatedEntryCount;
    }

    /**
     * Count all translated entries of these statistics
     *
     * @return int
     */
    public function getTranslatedEntryCount()
    {
        return $this->translatedEntryCount;
    }

    /**
     * Count all fuzzy entries of these statistics
     *
     * @return int
     */
    public function getFuzzyEntryCount()
    {
        return $this->fuzzyEntryCount;
    }

    /**
     * Count all faulty entries of these statistics
     *
     * @return int
     */
    public function getFaultyEntryCount()
    {
        return $this->faultyEntryCount;
    }
}