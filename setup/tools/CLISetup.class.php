<?php

namespace Aowow;

if (!defined('AOWOW_REVISION'))
    die('illegal access');

if (!CLI)
    die('not in cli mode');


class CLISetup
{
    public  static $locales       = [];

    public  static $srcDir        = 'setup/mpqdata/';

    private static $mpqFiles      = [];

    public  const SQL_BATCH       = 1000;                   // max. n items per sql insert

    public  const LOCK_OFF        = 0;
    public  const LOCK_ON         = 1;
    public  const LOCK_RESTORE    = 2;

    private static $lock          = self::LOCK_ON;

    public const ARGV_NONE        = 0x00;
    public const ARGV_REQUIRED    = 0x01;
    public const ARGV_OPTIONAL    = 0x02;
    public const ARGV_PARAM       = 0x04;                   // parameter to another argument
    public const ARGV_ARRAY       = 0x10;                   // arg accepts list of values

    public const OPT_GRP_SETUP    = 0;
    public const OPT_GRP_UTIL     = 1;
    public const OPT_GRP_MISC     = 2;

    private const GLOBALSTRINGS_LUA = '%s%sinterface/framexml/globalstrings.lua';

    private static $opts          = [];
    private static $optGroups     = ['AoWoW Setup', 'Utility Functions', 'Additional Options'];
    private static $optDefs       = array(                  // cmd => [groupId, aliases[], argvFlags, description, appendix]
        'delete'  => [self::OPT_GRP_MISC, ['d'], self::ARGV_NONE,                        'Delete dbc_* tables generated by this prompt when done. (not recommended)',                                 ''               ],
        'log'     => [self::OPT_GRP_MISC, [],    self::ARGV_REQUIRED,                    'Write CLI ouput to file.',                                                                                  '=logfile'       ],
        'help'    => [self::OPT_GRP_MISC, ['h'], self::ARGV_NONE,                        'Display contextual help, if available.',                                                                    ''               ],
        'force'   => [self::OPT_GRP_MISC, ['f'], self::ARGV_NONE,                        'Force existing files to be overwritten.',                                                                   ''               ],
        'locales' => [self::OPT_GRP_MISC, [],    self::ARGV_ARRAY | self::ARGV_OPTIONAL, 'Limit setup to enUS, frFR, deDE, zhCN, esES and/or ruRU. (does not override config settings)',              '=<regionCodes,>'],
        'datasrc' => [self::OPT_GRP_MISC, [],    self::ARGV_OPTIONAL,                    'Manually point to directory with extracted mpq files. This is limited to setup/ (default: setup/mpqdata/)', '=path/'         ],
    );

    private static $utilScriptRefs  = [];
    private static $setupScriptRefs = [];
    private static $tmpStore        = [];
    private static $gsFiles         = [];

    public static function registerUtility(UtilityScript $us) : void
    {
        if (isset(self::$optDefs[$us::COMMAND]) || isset(self::$utilScriptRefs[$us::COMMAND]))
        {
            CLI::write(' Utility function '.CLI::bold($us::COMMAND).' already defined.', CLI::LOG_ERROR);
            return;
        }
        self::$optDefs[$us::COMMAND] = [$us->optGroup, $us->argvOpts, $us->argvFlags, $us::DESCRIPTION, $us::APPENDIX];
        self::$utilScriptRefs[$us::COMMAND] = $us;
    }

    public static function registerSetup(string $invoker, SetupScript $ss) : void
    {
        if (isset(self::$optDefs[$invoker]) || isset(self::$utilScriptRefs[$invoker]))
        {
            CLI::write(' Utility function '.CLI::bold($invoker).' not defined. Can\'t attach Subscript '.CLI::bold($ss->getName()).', invoker is missing. Skipping...', CLI::LOG_ERROR);
            return;
        }

        if (isset(self::$setupScriptRefs[$invoker][$ss->getName()]))
        {
            CLI::write(' Subscript function '.CLI::bold($ss->getName()).' already defined for invoker '.CLI::bold($invoker).'. Skipping...', CLI::LOG_ERROR);
            return;
        }

        if ($childArgs = $ss->getSubCommands())
        {
            if ($duplicates = array_intersect(array_keys($childArgs), array_keys(self::$optDefs)))
            {
                CLI::write(' Subscript function '.CLI::bold($ss->getName()).'\'s child arguments --'.implode(', --', $duplicates).' are already defined. Skipping...', CLI::LOG_ERROR);
                return;
            }

            $newIdx = count(self::$optGroups);
            self::$optGroups[] = '--' . $invoker . '=' . $ss->getName();

            foreach ($childArgs as $cmd => [$aliases, $argFlags, $description])
                self::$optDefs[$cmd] = [$newIdx, $aliases, $argFlags, $description, ''];
        }

        // checks done ... store SetupScript
        if (self::checkDependencies($ss))
        {
            self::$setupScriptRefs[] = [$invoker, $ss->getName(), $ss];

            // recheck temp stored dependencies
            foreach (self::$tmpStore as $idx => [$invoker, $ts])
            {
                if (!self::checkDependencies($ts))
                    continue;

                self::$setupScriptRefs[] = [$invoker, $ts->getName(), $ts];
                unset(self::$tmpStore[$idx]);
            }
        }
        else                                                // if dependencies haven't been stored yet, put aside for later use
            self::$tmpStore[] = [$invoker, $ss];
    }

    private static function checkDependencies(SetupScript &$ss) : bool
    {
        if ($ss->isOptional)                                // optional scripts should no depend on anything
            return true;

        [$sDep, $bDep] = $ss->getSelfDependencies();

        return ((!$sDep || $sDep == array_intersect($sDep, array_column(array_filter(self::$setupScriptRefs, function($x) { return $x[0] == 'sql';   }), 1))) &&
                (!$bDep || $bDep == array_intersect($bDep, array_column(array_filter(self::$setupScriptRefs, function($x) { return $x[0] == 'build'; }), 1))));
    }

    public static function loadScripts() : void
    {
        foreach (glob('setup/tools/clisetup/*.us.php') as $file)
            include_once $file;

        if (self::$tmpStore)
        {
            CLI::write('Some SubScripts have unresolved dependencies and have not been loaded', CLI::LOG_ERROR);
            CLI::write();
            $tbl = [['Name', '--sql dep.', '--build dep.']];
            foreach (self::$tmpStore as [$_, $ssRef])
            {
                [$sDep, $bDep] = $ssRef->getSelfDependencies();

                $missS = array_intersect($sDep, array_column(array_filter(self::$setupScriptRefs, function($x) { return $x[0] == 'sql';   }), 1));
                $missB = array_intersect($sDep, array_column(array_filter(self::$setupScriptRefs, function($x) { return $x[0] == 'build'; }), 1));

                array_walk($sDep, function (&$x) use($missS) { $x = in_array($x, $missS) ? $x : CLI::red($x); });
                array_walk($bDep, function (&$x) use($missB) { $x = in_array($x, $missB) ? $x : CLI::red($x); });

                $tbl[] = [$ssRef->getName(), implode(', ', $sDep), implode(', ', $bDep)];
            }

            CLI::writeTable($tbl);
        }

        // link SubScipts back to UtilityScript after all UtilityScripts have been loaded
        foreach (self::$utilScriptRefs as $name => $us)
            if (in_array(__NAMESPACE__.'\TrSubScripts', class_uses($us)))
                $us->assignGenerators($name);

        self::evalOpts();
    }

    public static function getSubScripts(string $invoker = '') : \Generator
    {
        foreach (self::$setupScriptRefs as [$src, $name, $ref])
            if (!$invoker || $src == $invoker)
                yield $name => [$src, $ref];
    }

    public static function setLocales() : bool
    {
        // optional limit handled locales
        if (isset(self::$opts['locales']))
        {
            $opt = array_map('strtolower', self::$opts['locales']);
            foreach (Locale::cases() as $loc)
                if ($loc->validate() && array_intersect(array_map('strtolower', $loc->gameDirs()), $opt))
                    self::$locales[$loc->value] = $loc;
        }
        if (!self::$locales)
            foreach (Locale::cases() as $loc)
                if ($loc->validate())
                    self::$locales[$loc->value] = $loc;

        return !!self::$locales;
    }

    public static function init() : void
    {
        self::evalOpts();

        // optional logging
        if (isset(self::$opts['log']))
            CLI::initLogFile(trim(self::$opts['log']));

        // alternative data source (no quotes, use forward slash)
        if (isset(self::$opts['datasrc']))
            self::$srcDir = CLI::nicePath('', self::$opts['datasrc']);

        if (!self::setLocales())
            CLI::write('No valid locale specified. Check your config or --locales parameter, if used', CLI::LOG_ERROR);

        // get site status
        if (DB::isConnected(DB_AOWOW))
            self::$lock = Cfg::get('MAINTENANCE');
        else
            self::$lock = self::LOCK_ON;
    }

    public static function writeCLIHelp(bool $full = false) : void
    {
        $cmd = self::getOpt(1 << self::OPT_GRP_SETUP | 1 << self::OPT_GRP_UTIL);
        if (!$cmd || !self::$utilScriptRefs[$cmd[0]]->writeCLIHelp())
        {
            $lines = [];

            foreach (self::$optGroups as $idx => $og)
            {
                if (!$full && $idx > self::OPT_GRP_SETUP)
                    continue;

                $lines[] = [$og, ''];

                foreach (self::$optDefs as $opt => [$group, $alias, , $desc, $app])
                {
                    if ($group != $idx)
                        continue;

                    $cmd = '  --'.$opt;
                    foreach ($alias as $a)
                        $cmd .= ' | '.(strlen($a) == 1 ? '-'.$a : '--'.$a);

                    $lines[] = [$cmd.$app, $desc];
                }
            }

            CLI::writeTable($lines);
            CLI::write();
        }
    }

    // called from Setup
    public static function runInitial() : void
    {
        global $argc, $argv;                                // todo .. find better way? argv, argc are effectivley already global

        // get arguments present in argGroup 1 or 2, if set. Pick first.
        $cmd   = self::getOpt(1 << self::OPT_GRP_SETUP | 1 << self::OPT_GRP_UTIL)[0];
        $us    = &self::$utilScriptRefs[$cmd];
        $inOut = [null, null, null, null];
        $allOk = true;

        $i = 0;
        if ($us::USE_CLI_ARGS)
            foreach ($argv as $n => $arg)
            {
                if (!$n || ($arg && $arg[0] == '-'))        // not parent; not handled by getOpt()
                    continue;

                $inOut[$i++] = $arg;

                if ($i > 3)
                    break;
            }

        if ($dbError = array_filter($us::REQUIRED_DB, function ($x) { return !DB::isConnected($x); }))
        {
            CLI::write('Database on index '.implode(', ', $dbError).' not yet set up!', CLI::LOG_ERROR);
            CLI::write('Please use '.CLI::bold('"php aowow --db"').' for setup', CLI::LOG_BLANK);
            CLI::write();
            return;
        }

        if ($us::LOCK_SITE != self::LOCK_OFF)
            self::siteLock(self::LOCK_ON);

        if ($us::NOTE_START)
            CLI::write($us::NOTE_START);

        if (!$us->run($inOut))
            $allOk = false;

        $error = [];
        if ($allOk && !$us->test($error))
        {
            if ($us::NOTE_ERROR)
                CLI::write($us::NOTE_ERROR, CLI::LOG_ERROR);

            foreach ($error as $e)
                CLI::write($e, CLI::LOG_BLANK);

            CLI::write();
            $allOk = false;
        }

        if ($allOk)
            if ($ff = $us->followupFn)
                if (array_filter($inOut))
                    self::run($ff, $inOut);

        self::siteLock($us::LOCK_SITE == self::LOCK_RESTORE ? self::LOCK_RESTORE : self::LOCK_OFF);

        // end
        if ($us::NOTE_END_OK && $allOk)
            CLI::write($us::NOTE_END_OK, CLI::LOG_OK);
        else if($us::NOTE_END_FAIL && !$allOk)
            CLI::write($us::NOTE_END_FAIL, CLI::LOG_ERROR);
    }

    // consecutive calls
    public static function run(string $cmd, &$args) : bool
    {
        if (!isset(self::$utilScriptRefs[$cmd]))
            return false;

        $us = &self::$utilScriptRefs[$cmd];

        if ($dbError = array_filter($us::REQUIRED_DB, function ($x) { return !DB::isConnected($x); }))
        {
            CLI::write('Database on index '.implode(', ', $dbError).' not yet set up!', CLI::LOG_ERROR);
            CLI::write('Please use '.CLI::bold('"php aowow --db"').' for setup', CLI::LOG_BLANK);
            CLI::write();
            return false;
        }

        if ($us::PROMPT)
        {
            CLI::write($us::PROMPT, -1, false);
            CLI::write();

            if (!CLI::read(['x' => ['Press any key to continue', true, true]], $_))                // we don't actually care about the input
                return false;
        }

        $args = array_pad($args, 4, null);

        $success = $us->run($args);

        $error = [];
        if ($us::NOTE_ERROR && $success && !$us->test($error))
        {
            CLI::write($us::NOTE_ERROR, CLI::LOG_ERROR);
            foreach ($error as $e)
                CLI::write($e, CLI::LOG_BLANK);

            CLI::write();
            return false;
        }

        if ($success)
            if ($ff = $us->followupFn)
                if (array_filter($args))
                    if (!self::run($ff, $args))
                        $success = false;

        return $success;
    }


    /**************************/
    /* command line arguments */
    /**************************/

    public static function evalOpts() : void
    {
        $short = '';
        $long  = [];
        $alias = [];

        foreach (self::$optDefs as $opt => [, $aliases, $flags, , ])
        {
            foreach ($aliases as $i => $a)
            {
                if (isset($alias[$a]))
                    $alias[$a][] = $opt;
                else
                    $alias[$a] = [$opt];

                if ($flags & self::ARGV_REQUIRED)
                    $a .= ':';
                else if ($flags & self::ARGV_OPTIONAL)
                    $a .= '::';

                if (strlen($aliases[$i]) == 1)
                    $short .= $a;
                else
                    $long[] = $a;
            }

            if ($flags & self::ARGV_REQUIRED)
                $opt .= ':';
            else if ($flags & self::ARGV_OPTIONAL)
                $opt .= '::';

            $long[] = $opt;
        }

        if ($opts = getopt($short, $long))
        {
            foreach ($opts as $o => $v)
            {
                if (!isset($alias[$o]))
                    self::$opts[$o] = (self::$optDefs[$o][2] & self::ARGV_ARRAY) ? ($v ? explode(',', $v) : []) : ($v ?: true);
                else
                    foreach ($alias[$o] as $a)
                        self::$opts[$a] = (self::$optDefs[$a][2] & self::ARGV_ARRAY) ? ($v ? explode(',', $v) : []) : ($v ?: true);
            }
        }
    }

    public static function getOpt(/* string|int */ ...$args) // : bool|array|string
    {
        if (!$args)
            return false;

        $result = [];

        // groupMask case
        if (is_int($args[0]))
        {
            foreach (self::$optDefs as $o => [$group, , , , ])
                if (((1 << $group) & $args[0]) && isset(self::$opts[$o]))
                    $result[] = $o;

            return $result;
        }

        // single key case
        if (count($args) == 1)
            return self::$opts[$args[0]] ?? false;

        // multiple keys case
        foreach ($args as $a)
            if (isset(self::$optDefs[$a]))
                $result[$a] = self::$opts[$a] ?? false;

        return $result;
    }


    /*******************/
    /* web page access */
    /*******************/

    private static function siteLock(int $mode = self::LOCK_RESTORE) : void
    {
        if (DB::isConnected(DB_AOWOW))
            Cfg::set('MAINTENANCE', $mode == self::LOCK_RESTORE ? self::$lock : $mode);
    }


    /*******************/
    /* MPQ-file access */
    /*******************/

    /*  the problem
        1) paths provided in dbc files are case-insensitive and random
        2) paths to the actual textures contained in the mpq archives are case-insensitive and random
        unix systems will throw a fit if you try to get from one to the other, so lets save the paths from 2) and cast it to lowercase
        lookups will be done in lowercase. A successfull match will return the real path.
    */
    private static function buildFileList() : bool
    {
        CLI::write('indexing game data from '.self::$srcDir.' for first time use...', CLI::LOG_INFO, true, true);

        $setupDirs = glob('setup/*');
        foreach ($setupDirs as $sd)
        {
            if (mb_substr($sd, -1) == DIRECTORY_SEPARATOR)
                $sd = mb_substr($sd, 0, -1);

            if (Util::lower($sd) == Util::lower(self::$srcDir))
            {
                self::$srcDir = $sd.DIRECTORY_SEPARATOR;
                break;
            }
        }

        try
        {
            $iterator = new \RecursiveDirectoryIterator(self::$srcDir);
            $iterator->setFlags(\RecursiveDirectoryIterator::SKIP_DOTS);

            foreach (new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::SELF_FIRST) as $path)
            {
                $_ = CLI::nicePath($path->getPathname());
                self::$mpqFiles[strtolower($_)] = $_;
            }

            CLI::write('indexing game data from '.self::$srcDir.' for first time use... done!', CLI::LOG_INFO);
        }
        catch (\UnexpectedValueException $e)
        {
            CLI::write('- mpqData dir '.self::$srcDir.' does not exist', CLI::LOG_ERROR);
            return false;
        }

        return true;
    }

    public static function fileExists(string &$file) : bool
    {
        // read mpq source file structure to tree
        if (!self::$mpqFiles)
            if (!self::buildFileList())
                return false;

        // backslash to forward slash
        $_ = strtolower(CLI::nicePath($file));

        // remove trailing slash
        if (mb_substr($_, -1, 1) == DIRECTORY_SEPARATOR)
            $_ = mb_substr($_, 0, -1);

        if (isset(self::$mpqFiles[$_]))
        {
            $file = self::$mpqFiles[$_];
            return true;
        }

        return false;
    }

    public static function filesInPath(string $path, bool $useRegEx = false) : array
    {
        $result = [];

        // read mpq source file structure to tree
        if (!self::$mpqFiles)
            if (!self::buildFileList())
                return [];

        // backslash to forward slash
        $_ = strtolower(CLI::nicePath($path));

        foreach (self::$mpqFiles as $lowerFile => $realFile)
        {
            if (!$useRegEx && strstr($lowerFile, $_))
                $result[] = $realFile;
            else if ($useRegEx && preg_match($path, $lowerFile))
                $result[] = $realFile;
        }

        return $result;
    }

    public static function filesInPathLocalized(string $pathPattern, ?bool &$status = true, bool $matchAll = true) : array
    {
        $result = [];

        foreach (self::$locales as $locId => $loc)
        {
            foreach ($loc->gameDirs() as $gDir)
            {
                if ($gDir)                                  // if in subDir add trailing slash
                    $gDir .= DIRECTORY_SEPARATOR;

                $path = sprintf($pathPattern, $gDir);
                if (self::fileExists($path))
                {
                    $result[$locId] = $path;
                    break;
                }
            }
        }

        if (!$matchAll && !$result)
            $status = false;

        if ($matchAll && array_diff_key(self::$locales, $result))
            $status = false;

        return $result;
    }

    public static function loadGlobalStrings() : bool
    {
        CLI::write('loading required GlobalStrings', CLI::LOG_INFO);

        // try to load globalstrings for all selected locales
        foreach (self::$locales as $locId => $loc)
        {
            if (isset(self::$gsFiles[$locId]))
                continue;

            foreach ($loc->gameDirs() as $gDir)
            {
                if ($gDir)
                    $gDir .= DIRECTORY_SEPARATOR;

                $gsFile = sprintf(self::GLOBALSTRINGS_LUA, self::$srcDir, $gDir);
                if (self::fileExists($gsFile))
                {
                    self::$gsFiles[$locId] = file($gsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    break;
                }
            }
        }

        if ($missing = array_diff_key(self::$locales, self::$gsFiles))
        {
            ClI::write('GlobalStrings.lua not found for locale '. Lang::concat($missing, callback: fn($x) => $x->name), CLI::LOG_WARN);
            return false;
        }

        return true;
    }

    public static function searchGlobalStrings(string $pattern) : \Generator
    {
        if (!self::$gsFiles)
            return;

        foreach (self::$gsFiles as $lId => $globalStrings)
            foreach ($globalStrings as $gs)
                if (preg_match($pattern, $gs, $result))
                    yield $lId => $result;
    }


    /*****************/
    /* file handling */
    /*****************/

    public static function writeFile(string $file, string $content) : bool
    {
        if (Util::writeFile($file, $content))
        {
            CLI::write('created file '. CLI::bold($file), CLI::LOG_OK, true, true);
            return true;
        }

        return false;
    }

    public static function writeDir(string $dir, bool &$exist = true) : bool
    {
        if (Util::writeDir($dir, $exist))
        {
            if (!$exist)
                CLI::write('created dir '. CLI::bold($dir), CLI::LOG_OK, true, true);
            return true;
        }

        return false;
    }

    public static function loadDBC( string $name) : bool
    {
        if (!DB::isConnected(DB_AOWOW))
        {
            CLI::write('CLISetup::loadDBC() - not connected to DB. Cannot write results!', CLI::LOG_ERROR);
            return false;
        }

        if (DB::Aowow()->selectCell('SHOW TABLES LIKE ?', 'dbc_'.$name) && DB::Aowow()->selectCell('SELECT count(1) FROM ?#', 'dbc_'.$name))
            return true;

        $dbc = new DBC($name, ['temporary' => self::getOpt('delete')]);
        if ($dbc->error)
        {
            CLI::write('CLISetup::loadDBC() - required DBC '.$name.'.dbc not found!', CLI::LOG_ERROR);
            return false;
        }

        if (!$dbc->readFile())
        {
            CLI::write('CLISetup::loadDBC() - DBC '.$name.'.dbc could not be written to DB!', CLI::LOG_ERROR);
            return false;
        }

        return true;
    }
}

?>
