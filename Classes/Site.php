<?php
namespace ApacheSolrForTypo3\Solr;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2011-2015 Ingo Renner <ingo@typo3.org>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Page\PageRepository;

/**
 * A site is a branch in a TYPO3 installation. Each site's root page is marked
 * by the "Use as Root Page" flag.
 *
 * @author Ingo Renner <ingo@typo3.org>
 */
class Site
{
    /**
     * Cache for ApacheSolrForTypo3\Solr\Site objects
     *
     * @var array
     */
    protected static $sitesCache = [];

    /**
     * Small cache for the list of pages in a site, so that the results of this
     * rather expensive operation can be used by all initializers without having
     * each initializer do it again.
     *
     * TODO Move to caching framework once TYPO3 4.6 is the minimum required
     * version.
     *
     * @var array
     */
    protected static $sitePagesCache = [];

    /**
     * Root page record.
     *
     * @var array
     */
    protected $rootPage = [];

    /**
     * The site's sys_language_mode
     *
     * @var string
     */
    protected $sysLanguageMode = null;

    /**
     * Constructor.
     *
     * @param int $rootPageId Site root page ID (uid). The page must be marked as site root ("Use as Root Page" flag).
     */
    public function __construct($rootPageId)
    {
        $page = BackendUtility::getRecord('pages', $rootPageId);

        if (!$page['is_siteroot']) {
            throw new \InvalidArgumentException(
                'The page for the given page ID \'' . $rootPageId
                . '\' is not marked as root page and can therefore not be used as site root page.',
                1309272922
            );
        }

        $this->rootPage = $page;
    }

    /**
     * Gets the Site for a specific page Id.
     *
     * @param int $pageId The page Id to get a Site object for.
     * @return Site Site for the given page Id.
     */
    public static function getSiteByPageId($pageId)
    {
        $rootPageId = Util::getRootPageId($pageId);

        if (!isset(self::$sitesCache[$rootPageId])) {
            self::$sitesCache[$rootPageId] = GeneralUtility::makeInstance(__CLASS__,
                $rootPageId);
        }

        return self::$sitesCache[$rootPageId];
    }

    /**
     * Creates a dropdown selector of available TYPO3 sites with Solr
     * configured.
     *
     * @param string $selectorName Name to be used in the select's name attribute
     * @param Site $selectedSite Optional, currently selected site
     * @return string Site selector HTML code
     * @todo Extract into own class like indexing configuration selector
     */
    public static function getAvailableSitesSelector(
        $selectorName,
        Site $selectedSite = null
    ) {
        $sites = self::getAvailableSites();
        $selector = '<select name="' . $selectorName . '" class="form-control">';

        foreach ($sites as $site) {
            $selectedAttribute = '';
            if ($selectedSite !== null && $site->getRootPageId() == $selectedSite->getRootPageId()) {
                $selectedAttribute = ' selected="selected"';
            }

            $selector .= '<option value="' . $site->getRootPageId() . '"' . $selectedAttribute . '>'
                . $site->getLabel()
                . '</option>';
        }

        $selector .= '</select>';

        return $selector;
    }

    /**
     * Gets all available TYPO3 sites with Solr configured.
     *
     * @param bool $stopOnInvalidSite
     *
     * @return Site[] An array of available sites
     */
    public static function getAvailableSites($stopOnInvalidSite = false)
    {
        static $sitesCached;
        $sites = [];

        // Check if $sites has been cached
        if (isset($sitesCached)) {
            return $sitesCached;
        }

        $servers = self::getSolrServersFromRegistry();

        foreach ($servers as $server) {
            if (isset($sites[$server['rootPageUid']])) {
                //get each site only once
                continue;
            }

            try {
                $sites[$server['rootPageUid']] = GeneralUtility::makeInstance(__CLASS__, $server['rootPageUid']);
            } catch (\InvalidArgumentException $e) {
                if ($stopOnInvalidSite) {
                    throw $e;
                }
            }
        }

        $sitesCached = $sites;

        return $sitesCached;
    }

    /**
     * Returns the first available Site.
     *
     * @param bool $stopOnInvalidSite
     *
     * @return Site
     */
    public static function getFirstAvailableSite($stopOnInvalidSite = false)
    {
        $sites = self::getAvailableSites($stopOnInvalidSite);
        return array_shift($sites);
    }

    /**
     * Clears the $sitePagesCache
     *
     */
    public static function clearSitePagesCache()
    {
        self::$sitePagesCache = [];
    }

    /**
     * Gets the site's root page ID (uid).
     *
     * @return int The site's root page ID.
     */
    public function getRootPageId()
    {
        return $this->rootPage['uid'];
    }

    /**
     * Gets the site's label. The label is build from the the site title and root
     * page ID (uid).
     *
     * @return string The site's label.
     */
    public function getLabel()
    {
        $rootlineTitles = [];
        $rootLine = BackendUtility::BEgetRootLine($this->rootPage['uid']);
        // Remove last
        array_pop($rootLine);
        $rootLine = array_reverse($rootLine);
        foreach ($rootLine as $rootLineItem) {
            $rootlineTitles[] = $rootLineItem['title'];
        }
        return implode(' - ', $rootlineTitles) . ', Root Page ID: ' . $this->rootPage['uid'];
    }

    /**
     * Retrieves the configured solr servers from the registry.
     *
     * @return array
     */
    protected static function getSolrServersFromRegistry()
    {
        /** @var $registry Registry */
        $registry = GeneralUtility::makeInstance(Registry::class);
        $servers = (array) $registry->get('tx_solr', 'servers', []);
        return $servers;
    }

    /**
     * Gets the site's Solr TypoScript configuration (plugin.tx_solr.*)
     *
     * @return  \ApacheSolrForTypo3\Solr\System\Configuration\TypoScriptConfiguration The Solr TypoScript configuration
     */
    public function getSolrConfiguration()
    {
        return Util::getSolrConfigurationFromPageId($this->rootPage['uid']);
    }

    /**
     * Gets the system languages (IDs) for which Solr connections have been
     * configured.
     *
     * @return array Array of system language IDs for which connections have been configured on this site.
     */
    public function getLanguages()
    {
        $siteLanguages = [];

        $servers = self::getSolrServersFromRegistry();

        foreach ($servers as $connectionKey => $solrConnection) {
            list($siteRootPageId, $systemLanguageId) = explode('|',
                $connectionKey);

            if ($siteRootPageId == $this->rootPage['uid']) {
                $siteLanguages[] = $systemLanguageId;
            }
        }

        return $siteLanguages;
    }

    /**
     * Gets the site's default language as configured in
     * config.sys_language_uid. If sys_language_uid is not set, 0 is assumed to
     * be the default.
     *
     * @return int The site's default language.
     */
    public function getDefaultLanguage()
    {
        $siteDefaultLanguage = 0;

        $configuration = Util::getConfigurationFromPageId(
            $this->rootPage['uid'],
            'config',
            false,
            false
        );

        $siteDefaultLanguage = $configuration->getValueByPathOrDefaultValue('sys_language_uid', $siteDefaultLanguage);
        // default language is set through default L GET parameter -> overruling config.sys_language_uid
        $siteDefaultLanguage = $configuration->getValueByPathOrDefaultValue('defaultGetVars.L', $siteDefaultLanguage);

        return $siteDefaultLanguage;
    }

    /**
     * Generates a list of page IDs in this site. Attention, this includes
     * all page types! Deleted pages are not included.
     *
     * @param int|string $rootPageId Page ID from where to start collection sub pages
     * @param int $maxDepth Maximum depth to descend into the site tree
     * @return array Array of pages (IDs) in this site
     */
    public function getPages($rootPageId = 'SITE_ROOT', $maxDepth = 999)
    {
        // when we have a cached value, we can return it.
        if (!empty(self::$sitePagesCache[$rootPageId])) {
            return self::$sitePagesCache[$rootPageId];
        }

        $pageIds = [];
        $maxDepth = intval($maxDepth);

        $recursionRootPageId = intval($rootPageId);
        if ($rootPageId == 'SITE_ROOT') {
            $recursionRootPageId = $this->rootPage['uid'];
            $pageIds[] = (int) $this->rootPage['uid'];
        }

        if ($maxDepth <= 0) {
            // exiting the recursion loop, may write to cache now
            self::$sitePagesCache[$rootPageId] = $pageIds;
            return $pageIds;
        }

        // get the page ids of the current level and if needed call getPages recursive
        $pageIds = $this->getPageIdsFromCurrentDepthAndCallRecursive($maxDepth, $recursionRootPageId, $pageIds);

        // exiting the recursion loop, may write to cache now
        self::$sitePagesCache[$rootPageId] = $pageIds;
        return $pageIds;
    }

    /**
     * This method retrieves the pages ids from the current tree level an calls getPages recursive,
     * when the maxDepth has not been reached.
     *
     * @param int $maxDepth
     * @param int $recursionRootPageId
     * @param array $pageIds
     * @return array
     */
    protected function getPageIdsFromCurrentDepthAndCallRecursive($maxDepth, $recursionRootPageId, $pageIds)
    {
        static $initialPagesAdditionalWhereClause;

        // Only fetch $initialPagesAdditionalWhereClause on first call
        if (empty($initialPagesAdditionalWhereClause)) {
            // Fetch configuration in order to be able to read initialPagesAdditionalWhereClause
            $configuration = Util::getSolrConfigurationFromPageId($this->rootPage['uid']);
            $initialPagesAdditionalWhereClause = $configuration->getInitialPagesAdditionalWhereClause();
        }

        $result = $GLOBALS['TYPO3_DB']->exec_SELECTquery('uid', 'pages', 'pid = ' . $recursionRootPageId . ' ' . BackendUtility::deleteClause('pages') . $initialPagesAdditionalWhereClause);

        while ($page = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result)) {
            $pageIds[] = (int) $page['uid'];

            if ($maxDepth > 1) {
                $pageIds = array_merge($pageIds, $this->getPages($page['uid'], $maxDepth - 1));
            }
        }
        $GLOBALS['TYPO3_DB']->sql_free_result($result);
        return $pageIds;
    }

    /**
     * Generates the site's unique Site Hash.
     *
     * The Site Hash is build from the site's main domain, the system encryption
     * key, and the extension "tx_solr". These components are concatenated and
     * sha1-hashed.
     *
     * @return string Site Hash.
     */
    public function getSiteHash()
    {
        return Util::getSiteHashForDomain($this->getDomain());
    }

    /**
     * Gets the site's main domain. More specifically the first domain record in
     * the site tree.
     *
     * @return string The site's main domain.
     */
    public function getDomain()
    {
        $pageSelect = GeneralUtility::makeInstance(PageRepository::class);
        $rootLine = $pageSelect->getRootLine($this->rootPage['uid']);

        return BackendUtility::firstDomainRecord($rootLine);
    }

    /**
     * Gets the site's root page.
     *
     * @return array The site's root page.
     */
    public function getRootPage()
    {
        return $this->rootPage;
    }

    /**
     * Gets the site's root page's title.
     *
     * @return string The site's root page's title
     */
    public function getTitle()
    {
        return $this->rootPage['title'];
    }

    /**
     * Gets the site's config.sys_language_mode setting
     *
     * @return string The site's config.sys_language_mode
     */
    public function getSysLanguageMode()
    {
        if (is_null($this->sysLanguageMode)) {
            Util::initializeTsfe($this->getRootPageId());
            $this->sysLanguageMode = $GLOBALS['TSFE']->sys_language_mode;
        }

        return $this->sysLanguageMode;
    }
}
