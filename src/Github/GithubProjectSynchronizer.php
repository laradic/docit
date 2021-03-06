<?php
/**
 * Part of the Robin Radic's PHP packages.
 *
 * MIT License and copyright information bundled with this package
 * in the LICENSE file or visit http://radic.mit-license.com
 */
namespace Laradic\Docit\Github;

use Cache;
use File;
use GrahamCampbell\GitHub\GitHubManager;
use Laradic\Docit\Contracts\DocitLog;
use Laradic\Docit\Contracts\ProjectSynchronizer;
use Laradic\Docit\Projects\Project;
use Laradic\Docit\Projects\ProjectFactory;
use Laradic\Support\Arrays;
use Laradic\Support\Path;
use Laradic\Support\String;
use Monolog\Logger;
use Naneau\SemVer\Compare;
use Naneau\SemVer\Parser;
use Symfony\Component\Yaml\Yaml;


/**
 * This is the GithubProjectSynchronizer class.
 *
 * @package        Laradic\Docit
 * @version        1.0.0
 * @author         Robin Radic
 * @license        MIT License
 * @copyright      2015, Robin Radic
 * @link           https://github.com/robinradic
 */
class GithubProjectSynchronizer implements ProjectSynchronizer
{

    protected $gm;

    protected $projects;

    protected $log;

    /**
     * Instanciates the class
     *
     * @param \GrahamCampbell\GitHub\GitHubManager   $gm
     * @param \Laradic\Docit\Projects\ProjectFactory $projects
     * @param \Laradic\Docit\Contracts\DocitLog      $log
     */
    public function __construct(GithubManager $gm, ProjectFactory $projects, DocitLog $log)
    {
        $this->gm       = $gm;
        $this->projects = $projects;
        $this->log      = $log;
    }

    public function getLog()
    {
        return $this->log;
    }

    public function setLog(Logger $log)
    {
        $this->log = $log;
    }

    protected function resolveProject($project)
    {
        if ( ! $project instanceof Project )
        {
            if ( ! $this->projects->has($project) )
            {
                $this->log->error('resolve project failed: could not find project', [ 'project' => $project ]);

                return false; #throw new Exception('Project does not exists for sync by git');
            }

            $project = $this->projects->make($project);
        }

        $config = $project->getConfig()[ 'github' ];

        if ( $config[ 'enabled' ] !== true )
        {
            $this->log->error('resolve project failed: project has github disabled', [ 'project' => $project ]);

            return false; #throw new Exception('Project has github disabled, what am i doing here..');
        }

        return $project;
    }

    protected function getCacheKey(Project $project, $ref)
    {
        return md5($project->getSlug() . $ref);
    }

    protected function getPaths(Project $project, $ref, $type)
    {
        $paths = [
            'docs'     => 'docs',
            'logs'     => 'build/logs',
            'index_md' => 'docs/index.md'
        ];

        $b = $project[ 'github.path_bindings' ];

        if ( isset($b) )
        {
            foreach ( $b as $k => $v )
            {
                $paths[ $k ] = $v;
            }
        }


        $paths[ 'local.project' ] = $project->getPath();

        $folder = $ref;

        if ( $type === 'tag' )
        {
            $tag    = Parser::parse(String::remove($ref, 'v'));
            $folder = $tag->getMajor() . '.' . $tag->getMinor();
        }


        $paths[ 'local.destination' ] = Path::join($paths[ 'local.project' ], $folder);

        return $paths;
    }


    public function sync($project)
    {
        $project = $this->resolveProject($project);
        $tags    = $this->getUnsyncedTags($project);
        $this->log->info('synchronising project ' . $project->getSlug(), [ 'project' => $project ]);

        foreach ( $tags as $tag )
        {
            $this->syncTag($project, $tag);
        }

        $branches = $this->getUnsyncedBranches($project);
        foreach ( $branches as $branch )
        {
            $this->syncBranch($project, $branch);
        }
        $this->log->info('synchronized project ' . $project->getSlug(), [ 'project' => $project ]);
    }


    /**
     * @param \Laradic\Docit\Projects\Project $project
     * @param string                          $ref  tag name OR branch name
     * @param string                          $type [ tag | branch ]
     */
    protected function syncRef(Project $project, $ref, $type)
    {
        $paths = $this->getPaths($project, $ref, $type);

        $this->log->info("synchronizing docs for $type $ref ", [ 'project' => $project->getSlug(), "$type" => $ref, 'paths' => $paths ]);

        $content = new RepoContent($project[ 'github.username' ], $project[ 'github.repository' ], $this->gm);

        $hasDocs = $content->exists($paths[ 'docs' ], $ref);
        #$hasLogs  = $content->exists($paths['logs'], $ref);
        #$hasIndex = $content->exists($paths['index_md'], $ref);

        if ( $hasDocs )
        {


            # parse menu and get pages to sync
            $menu            = $content->show(Path::join($paths[ 'docs' ], 'menu.yml'), $ref);
            $menuContent     = base64_decode($menu[ 'content' ]);
            $menuArray       = Yaml::parse($menuContent);
            $unfilteredPages = [ ];
            $this->extractPagesFromMenu($menuArray[ 'menu' ], $unfilteredPages);
            $filteredPages = [ ];
            foreach ( $unfilteredPages as $page ) # filter out pages that link to external sites
            {
                if ( String::startsWith($page, 'http') || String::startsWith($page, '//') || String::startsWith($page, 'git') )
                {
                    continue;
                }
                if ( ! in_array($page, $filteredPages) )
                {
                    $filteredPages[ ] = $page;
                }
            }

            # get all pages their content and save to local
            foreach ( $filteredPages as $pagePath )
            {
                $path = Path::join($paths[ 'docs' ], $pagePath . '.md');

                # check if page exists on remote
                $exists = $content->exists($path, $ref);
                if ( ! $exists )
                {
                    continue;
                }

                # the raw github page content response
                $pageRaw = $content->show('/' . $path, $ref);

                # transform remote directory path to local directory path
                $dir = String::remove($pageRaw[ 'path' ], $paths[ 'docs' ]);
                $dir = String::remove($dir, $pageRaw[ 'name' ]);
                $dir = Path::canonicalize(Path::join($paths[ 'local.destination' ], $dir));
                if ( ! File::isDirectory($dir) )
                {
                    File::makeDirectory($dir, 0777, true);
                }

                # raw github page to utf8 and save it to local
                File::put(Path::join($dir, $pageRaw[ 'name' ]), base64_decode($pageRaw[ 'content' ]));
            }

            # save the menu to local
            File::put(Path::join($paths[ 'local.destination' ], 'menu.yml'), $menuContent);

            # if enabled, Get phpdoc structure and save it
            if ( isset($project[ 'phpdoc' ]) and $project[ 'phpdoc' ][ 'enabled' ] === true )
            {

                $hasStructure = $content->exists($project[ 'phpdoc' ][ 'github_xml_path' ], $ref);
                if ( $hasStructure )
                {
                    $structure    = $content->show($project[ 'phpdoc' ][ 'github_xml_path' ], $ref);
                    $structureXml = base64_decode($structure[ 'content' ]);
                    $this->log->info('got structure', [ 'structureXml' => $structureXml ]);

                    $destination    = Path::join($paths[ 'local.destination' ], $project[ 'phpdoc' ][ 'dir' ], 'structure.xml');
                    $destinationDir = Path::getDirectory($destination);
                    $this->log->info('writing to ' . $destination, [ 'destination ' => $destination, 'destinationDir' => $destinationDir ]);

                    if ( ! File::isDirectory($destinationDir) )
                    {
                        $this->log->info('Destination dir does not exist. Creating it ' . $destination, [ 'destinationDir' => $destinationDir ]);
                        File::makeDirectory($destinationDir, 0755, true);
                    }
                    File::put($destination, $structureXml);
                }
                else
                {
                    $this->log->error("Could not synchronize phpunit for $type $ref. Could not find structure.xml", [ 'project' => $project, "$type" => $ref, 'path' => $project[ 'phpdoc' ][ 'github_xml_path' ] ]);
                }
            }

            # set cache sha for branches, not for tags (obviously)
            if ( $type === 'branch' )
            {
                $branchData = $this->gm->repo()->branches($project[ 'github.username' ], $project[ 'github.repository' ], $ref);
                Cache::forever($this->getCacheKey($project, $ref), $branchData[ 'commit' ][ 'sha' ]);
            }
        }
        else
        {
            $this->log->error("Could not synchronize docs for $type $ref. Could not find docs", [ 'project' => $project, "$type" => $ref ]);
        }
    }

    public function syncBranch($project, $branch)
    {
        if ( ! $project = $this->resolveProject($project) )
        {
            return false;
        }

        [ 'github' ];

        if ( ! isset($project[ 'github.branches' ]) or ! is_array($project[ 'github.branches' ]) or ! in_array($branch, $project[ 'github.branches' ]) )
        {
            return false;
        }

        $this->syncRef($project, $branch, 'branch');
    }

    public function syncTag($project, $tag)
    {
        if ( ! $project = $this->resolveProject($project) )
        {
            return false;
        }

        $this->syncRef($project, $tag, 'tag');
    }


    public function getUnsyncedBranches($project)
    {
        $this->log->info('getting unsynced branches', [ 'project' => $project ]);
        if ( ! $project = $this->resolveProject($project) )
        {
            return [ ];
        }

        if ( ! isset($project[ 'github.branches' ]) or ! is_array($project[ 'github.branches' ]) or count($project[ 'github.branches' ]) === 0 )
        {
            return [ ];
        }


        $branches       = $this->gm->repo()->branches($project[ 'github.username' ], $project[ 'github.repository' ]);
        $branchesToSync = [ ];
        foreach ( $branches as $branch )
        {
            $name     = $branch[ 'name' ];
            $paths    = $this->getPaths($project, $name, 'branch');
            $sha      = $branch[ 'commit' ][ 'sha' ];
            $cacheKey = md5($project->getSlug() . $name);
            $branch   = Cache::get($cacheKey, false);
            if ( $branch !== $sha or $branch === false or ! File::isDirectory($paths[ 'local.destination' ]) )
            {
                $branchesToSync[ ] = $name;
                $this->log->info("marking branch $name for synchronisation", [ 'project' => $project->getSlug(), 'branch' => $branch ]);
            }
            else
            {
                $this->log->info("skipping branch $name", [ 'project' => $project->getSlug(), 'branch' => $branch ]);
            }
        }
        $b = $branchesToSync;

        return $branchesToSync;
    }

    public function getUnsyncedTags($project)
    {
        $this->log->info('getting unsynced tags', [ 'project' => $project ]);
        if ( ! $project = $this->resolveProject($project) )
        {
            return [ ];
        }

        $currentVersions = Arrays::keys($project->getVersions());


        $tagsToSync = [ ];
        $excludes   = $project[ 'github.exclude_tags' ];
        $start      = is_string($project[ 'github.start_at_tag' ]) ? Parser::parse(String::remove($project[ 'github.start_at_tag' ], 'v')) : false;

        $tags = $this->gm->repo()->tags($project[ 'github.username' ], $project[ 'github.repository' ]);
        foreach ( $tags as $tag )
        {
            $tagVersion = $tag[ 'name' ];
            #

            $tagVersionParsed = Parser::parse(String::remove($tag[ 'name' ], 'v'));
            $tagVersionShort  = $tagVersionParsed->getMajor() . '.' . $tagVersionParsed->getMinor();

            if ( ($start !== false AND Compare::smallerThan(Parser::parse($tagVersionParsed), $start))
                OR
                (in_array($tagVersion, $excludes) OR in_array($tagVersionShort, $currentVersions))
            )
            {
                $this->log->info("skipping tag $tagVersion", [ 'project' => $project->getSlug(), 'tag' => $tagVersion ]);
                continue;
            }
            $this->log->info("marking tag $tagVersion for synchronisation", [ 'project' => $project->getSlug(), 'tag' => $tagVersion ]);

            $tagsToSync[ ] = $tagVersion;
        }

        return $tagsToSync;
    }

    public function extractPagesFromMenu($menuArray, &$pages = [ ])
    {
        foreach ( $menuArray as $key => $val )
        {
            if ( is_string($key) && is_string($val) )
            {
                $pages[ ] = $val;
            }
            elseif ( is_string($key) && $key === 'children' && is_array($val) )
            {
                $this->extractPagesFromMenu($val, $pages);
            }
            elseif ( isset($val[ 'name' ]) )
            {
                if ( isset($val[ 'page' ]) )
                {
                    $pages[ ] = $val[ 'page' ];
                }
                if ( isset($val[ 'href' ]) )
                {
                    //$item['href'] = $this->resolveLink($val['href']);
                }
                if ( isset($val[ 'icon' ]) )
                {
                    //$item['icon'] = $val['icon'];
                }
                if ( isset($val[ 'children' ]) && is_array($val[ 'children' ]) )
                {
                    $this->extractPagesFromMenu($val[ 'children' ], $pages);
                }
            }
        }
    }


}
