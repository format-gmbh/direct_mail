<?php
declare(strict_types=1);

namespace DirectMailTeam\DirectMail\Module;

use DirectMailTeam\DirectMail\DirectMailUtility;
use DirectMailTeam\DirectMail\Repository\SysDmailRepository;
use DirectMailTeam\DirectMail\Repository\SysDmailMaillogRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

class StatisticsController extends MainController
{   
    /**
     * The name of the module
     *
     * @var string
     */
    protected $moduleName = 'DirectMailNavFrame_Statistics';
    
    private int $uid = 0;
    private string $table = '';
    private array $tables = ['tt_address', 'fe_users'];
    private bool $recalcCache = false;
    
    protected function initStatistics(ServerRequestInterface $request): void {
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        
        $this->uid = (int)($parsedBody['uid'] ?? $queryParams['uid'] ?? 0);
        
        $table = (string)($parsedBody['table'] ?? $queryParams['table'] ?? '');
        if(in_array($table, $this->tables)) {
            $this->table = (string)($table);
        }
        
        $this->recalcCache = (bool)($parsedBody['recalcCache'] ?? $queryParams['recalcCache'] ?? false);
    }
    
    public function indexAction(ServerRequestInterface $request) : ResponseInterface
    {
        $this->view = $this->configureTemplatePaths('Statistics');
        
        $this->init($request);
        $this->initStatistics($request);
        
        if (($this->id && $this->access) || ($this->isAdmin() && !$this->id)) {
            $module = $this->getModulName();

            if ($module == 'dmail') {
                // Direct mail module
                if (($this->pageinfo['doktype'] ?? 0) == 254) {
                    $data = $this->moduleContent();
                    $this->view->assignMultiple(
                        [
                            'data' => $data,
                            'show' => true
                        ]
                    );
                }
                elseif ($this->id != 0) {
                    $message = $this->createFlashMessage($this->getLanguageService()->getLL('dmail_noRegular'), $this->getLanguageService()->getLL('dmail_newsletters'), 1, false);
                    $this->messageQueue->addMessage($message);
                }
            }
            else {
                $message = $this->createFlashMessage($this->getLanguageService()->getLL('select_folder'), $this->getLanguageService()->getLL('header_stat'), 1, false);
                $this->messageQueue->addMessage($message);
            }
        }
        else {
            // If no access or if ID == zero
            $this->view = $this->configureTemplatePaths('NoAccess');
            $message = $this->createFlashMessage('If no access or if ID == zero', 'No Access', 1, false);
            $this->messageQueue->addMessage($message);
        }

        /**
         * Render template and return html content
         */
        $this->moduleTemplate->setContent($this->view->render());
        return new HtmlResponse($this->moduleTemplate->renderContent());
    }
    
    protected function moduleContent()
    {
        $theOutput = [];
        
        if (!$this->sys_dmail_uid) {
            $theOutput['dataPageInfo'] = $this->displayPageInfo();
        } 
        else {
            $row = GeneralUtility::makeInstance(SysDmailRepository::class)->selectSysDmailById($this->sys_dmail_uid, $this->id);
            
//          $this->noView = 0;
            if (is_array($row)) {
                // Set URL data for commands
                $this->setURLs($row);
                
                // COMMAND:
                switch ($this->cmd) {
                    case 'displayUserInfo':
                        $theOutput['dataUserInfo'] = $this->displayUserInfo();
                        break;
                    case 'stats':
                        $theOutput['dataStats'] = $this->stats($row);
                        break;
                    default:
                        // Hook for handling of custom direct mail commands:
                        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXT']['directmail']['handledirectmailcmd-' . $this->cmd])) {
                            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXT']['directmail']['handledirectmailcmd-' . $this->cmd] as $funcRef) {
                                $params = ['pObj' => &$this];
                                $theOutput['dataHook'] = GeneralUtility::callUserFunction($funcRef, $params, $this);
                            }
                        }
                }
            }
        }
        return $theOutput;
    }
    
    /**
     * Shows the info of a page
     *
     * @return string The infopage of the sent newsletters
     */
    protected function displayPageInfo()
    {
        // Here the dmail list is rendered:
        $rows = GeneralUtility::makeInstance(SysDmailRepository::class)->selectForPageInfo($this->id);
        $data = [];
        if (is_array($rows)) {
            foreach ($rows as $row)  {
                $data[] = [
                    'icon'            => $this->iconFactory->getIconForRecord('sys_dmail', $row, Icon::SIZE_SMALL)->render(),
                    'subject'         => $this->linkDMail_record(GeneralUtility::fixed_lgd_cs($row['subject'], 30) . '  ', $row['uid'], $row['subject']),
                    'scheduled'       => BackendUtility::datetime($row['scheduled']),
                    'scheduled_begin' => $row['scheduled_begin'] ? BackendUtility::datetime($row['scheduled_begin']) : '',
                    'scheduled_end'   => $row['scheduled_end'] ? BackendUtility::datetime($row['scheduled_end']) : '',
                    'sent'            => $row['count'] ? $row['count'] : '',
                    'status'          => $this->getSentStatus($row)
                ];
            }
        }

        return $data;
    }
    
    protected function getSentStatus(array $row): string {
        if (!empty($row['scheduled_begin'])) {
            if (!empty($row['scheduled_end'])) {
                $sent = $this->getLanguageService()->getLL('stats_overview_sent');
            } else {
                $sent = $this->getLanguageService()->getLL('stats_overview_sending');
            }
        } else {
            $sent = $this->getLanguageService()->getLL('stats_overview_queuing');
        }
        return $sent;
    }
    
    /**
     * Shows user's info and categories
     *
     * @return string HTML showing user's info and the categories
     */
    protected function displayUserInfo()
    {
        $uid = $this->uid;
        $table = $this->table;
        $indata = GeneralUtility::_GP('indata');

        $mmTable = $GLOBALS['TCA'][$table]['columns']['module_sys_dmail_category']['config']['MM'];
        
        if (GeneralUtility::_GP('submit')) {
            if (!$indata) {
                $indata['html'] = 0;
            }
        }
        
        switch ($table) {
            case 'tt_address':
                // see fe_users
            case 'fe_users':
                if (is_array($indata)) {
                    $data = [];
                    if (is_array($indata['categories'])) {
                        reset($indata['categories']);
                        foreach ($indata['categories'] as $recValues) {
                            $enabled = [];
                            foreach ($recValues as $k => $b) {
                                if ($b) {
                                    $enabled[] = $k;
                                }
                            }
                            $data[$table][$uid]['module_sys_dmail_category'] = implode(',', $enabled);
                        }
                    }
                    $data[$table][$uid]['module_sys_dmail_html'] = $indata['html'] ? 1 : 0;
                    
                    /* @var $tce \TYPO3\CMS\Core\DataHandling\DataHandler */
                    $tce = GeneralUtility::makeInstance(DataHandler::class);
                    $tce->stripslashes_values = 0;
                    $tce->start($data, []);
                    $tce->process_datamap();
                }
                break;
            default:
                // do nothing
        }
        
        switch ($table) {
            case 'tt_address':
                $queryBuilder = $this->getQueryBuilder('sys_language');
                $res = $queryBuilder
                ->select('tt_address.*')
                ->from('tt_address','tt_address')
                ->leftjoin(
                    'tt_address',
                    'pages',
                    'pages',
                    $queryBuilder->expr()->eq('pages.uid', $queryBuilder->quoteIdentifier('tt_address.pid'))
                    )
                    ->add('where','tt_address.uid=' . intval($uid) .
                        ' AND ' . $this->perms_clause . ' AND pages.deleted = 0')
                        ->execute();
                        break;
            case 'fe_users':
                $queryBuilder = $this->getQueryBuilder('fe_users');
                $res = $queryBuilder
                ->select('fe_users.*')
                ->from('fe_users','fe_users')
                ->leftjoin(
                    'fe_users',
                    'pages',
                    'pages',
                    $queryBuilder->expr()->eq('pages.uid', $queryBuilder->quoteIdentifier('fe_users.pid'))
                    )
                    ->add('where','fe_users.uid=' . intval($uid) .
                        ' AND ' . $this->perms_clause . ' AND pages.deleted = 0')
                        ->execute();
                        break;
            default:
                // do nothing
        }
        
        $row = [];
        if ($res) {
            $row = $res->fetch();
        }
        
        $theOutput = '';
        if (is_array($row)) {
            $categories = '';
            $queryBuilder = $this->getQueryBuilder($mmTable);
            $resCat = $queryBuilder
            ->select('uid_foreign')
            ->from($mmTable)
            ->add('where','uid_local=' . $row['uid'])
            ->execute();
            while (($rowCat = $resCat->fetch())) {
                $categories .= $rowCat['uid_foreign'] . ',';
            }
            $categories = rtrim($categories, ',');
            
            $editOnClickLink = DirectMailUtility::getEditOnClickLink([
                'edit' => [
                    $table => [
                        $row['uid'] => 'edit',
                    ],
                ],
                'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI'),
            ]);
            
            $out = '';
            $out .=  $this->iconFactory->getIconForRecord($table, $row)->render() . htmlspecialchars($row['name'] . ' <' . $row['email'] . '>');
            $out .= '&nbsp;&nbsp;<a href="#" onClick="' . $editOnClickLink . '" title="' . $this->getLanguageService()->getLL('dmail_edit') . '">' .
                $this->iconFactory->getIcon('actions-open', Icon::SIZE_SMALL) .
                $this->getLanguageService()->getLL('dmail_edit') . '</b></a>';
                $theOutput = '<h3>' . $this->getLanguageService()->getLL('subscriber_info') . '</h3>' . $out;
                
                $out = '';
                
                $this->categories = DirectMailUtility::makeCategories($table, $row, $this->sys_language_uid);
                
                foreach ($this->categories as $pKey => $pVal) {
                    $out .='<input type="hidden" name="indata[categories][' . $row['uid'] . '][' . $pKey . ']" value="0" />' .
                        '<input type="checkbox" name="indata[categories][' . $row['uid'] . '][' . $pKey . ']" value="1"' . (GeneralUtility::inList($categories, $pKey)?' checked="checked"':'') . ' /> ' .
                        htmlspecialchars($pVal) . '<br />';
                }
                $out .= '<br /><br /><input type="checkbox" name="indata[html]" value="1"' . ($row['module_sys_dmail_html']?' checked="checked"':'') . ' /> ';
                $out .= $this->getLanguageService()->getLL('subscriber_profile_htmlemail') . '<br />';
                
                $out .= '<input type="hidden" name="table" value="' . $table . '" />' .
                    '<input type="hidden" name="uid" value="' . $uid . '" />' .
                    '<input type="hidden" name="cmd" value="' . $this->cmd . '" /><br />' .
                    '<input type="submit" name="submit" value="' . htmlspecialchars($this->getLanguageService()->getLL('subscriber_profile_update')) . '" />';
                $theOutput .= '<div style="padding-top: 20px;"></div>';
                $theOutput .= '<h3>' . $this->getLanguageService()->getLL('subscriber_profile') . '</h3>' .
                    $this->getLanguageService()->getLL('subscriber_profile_instructions') . '<br /><br />' . $out;
        }
        
        return $theOutput;
    }
    
    /**
     * Get statistics from DB and compile them.
     *
     * @param array $row DB record
     *
     * @return string Statistics of a mail
     * @throws RouteNotFoundException If the named route doesn't exist
     */
    protected function stats($row)
    {
        if ($this->recalcCache) {
            $this->makeStatTempTableContent($row);
        }
        
        $thisurl = $this->buildUriFromRoute(
            $this->moduleName,
            [
                'id' => $this->id,
                'sys_dmail_uid' => $row['uid'],
                'cmd' => $this->cmd,
                'recalcCache' => 1
            ]
        );

        $compactView = $this->directMail_compactView($row);
        // *****************************
        // Mail responses, general:
        // *****************************
        
        $mailingId = intval($row['uid']);
        $fieldRows = 'response_type';
        $addFieldRows = '*';
        $tableRows =  'sys_dmail_maillog';
        $whereRows = 'mid=' . $mailingId;
        $groupByRows = 'response_type';
        $orderByRows = '';
        $queryArray = [$fieldRows, $addFieldRows, $tableRows, $whereRows, $groupByRows, $orderByRows];
        
        $table = $this->getQueryRows($queryArray, 'response_type');
        
        // Plaintext/HTML       
        $res = GeneralUtility::makeInstance(SysDmailMaillogRepository::class)->countSysDmailMaillogAllByMid($mailingId);

        /* this function is called to change the key from 'COUNT(*)' to 'counter' */
        $res = $this->changekeyname($res,'counter','COUNT(*)');
        
        $textHtml = [];
        foreach($res as $row2){
            // 0:No mail; 1:HTML; 2:TEXT; 3:HTML+TEXT
            $textHtml[$row2['html_sent']] = $row2['counter'];
        }
        
        // Unique responses, html
        $res = GeneralUtility::makeInstance(SysDmailMaillogRepository::class)->countSysDmailMaillogHtmlByMid($mailingId);
        $uniqueHtmlResponses = count($res);//sql_num_rows($res);
        
        // Unique responses, Plain
        $res = GeneralUtility::makeInstance(SysDmailMaillogRepository::class)->countSysDmailMaillogPlainByMid($mailingId);
        $uniquePlainResponses = count($res); //sql_num_rows($res);
        
        // Unique responses, pings
        $res = GeneralUtility::makeInstance(SysDmailMaillogRepository::class)->countSysDmailMaillogPingByMid($mailingId);
        $uniquePingResponses = count($res); //sql_num_rows($res);
        
        $tblLines = [];
        $tblLines[] = [
            '',
            $this->getLanguageService()->getLL('stats_total'),
            $this->getLanguageService()->getLL('stats_HTML'),
            $this->getLanguageService()->getLL('stats_plaintext')
        ];

        $totalSent = intval(($textHtml['1'] ?? 0) + ($textHtml['2'] ?? 0) + ($textHtml['3'] ?? 0));
        $htmlSent  = intval(($textHtml['1'] ?? 0) + ($textHtml['3'] ?? 0));
        $plainSent = intval(($textHtml['2'] ?? 0));
        
        $tblLines[] = [
            $this->getLanguageService()->getLL('stats_mails_sent'),
            $totalSent,
            $htmlSent,
            $plainSent
        ];
        $tblLines[] = [
            $this->getLanguageService()->getLL('stats_mails_returned'),
            $this->showWithPercent($table['-127']['counter'] ?? 0, $totalSent)
        ];
        $tblLines[] = [
            $this->getLanguageService()->getLL('stats_HTML_mails_viewed'),
            '',
            $this->showWithPercent($uniquePingResponses, $htmlSent)
        ];
        $tblLines[] = [
            $this->getLanguageService()->getLL('stats_unique_responses'),
            $this->showWithPercent($uniqueHtmlResponses+$uniquePlainResponses, $totalSent),
            $this->showWithPercent($uniqueHtmlResponses, $htmlSent),
            $this->showWithPercent($uniquePlainResponses, $plainSent?$plainSent:$htmlSent)];
        
        $output = '<br /><h2>' . $this->getLanguageService()->getLL('stats_general_information') . '</h2>';
        $output .= DirectMailUtility::formatTable($tblLines, ['nowrap', 'nowrap', 'nowrap', 'nowrap'], 1, []);
        
        // ******************
        // Links:
        // ******************
        
        // initialize $urlCounter
        $urlCounter =  [
            'total' => [],
            'plain' => [],
            'html' => [],
        ];
        // Most popular links, html:
        $fieldRows = 'url_id';
        $addFieldRows = '*';
        $tableRows =  'sys_dmail_maillog';
        $whereRows = 'mid=' . intval($row['uid']) . ' AND response_type=1';
        $groupByRows = 'url_id';
        $orderByRows = 'COUNT(*)';
        //$queryArray = ['url_id,count(*) as counter', 'sys_dmail_maillog', 'mid=' . intval($row['uid']) . ' AND response_type=1', 'url_id', 'counter'];
        $queryArray = [$fieldRows, $addFieldRows, $tableRows, $whereRows, $groupByRows, $orderByRows];
        $htmlUrlsTable = $this->getQueryRows($queryArray, 'url_id');
        
        // Most popular links, plain:
        $fieldRows = 'url_id';
        $addFieldRows = '*';
        $tableRows =  'sys_dmail_maillog';
        $whereRows = 'mid=' . intval($row['uid']) . ' AND response_type=2';
        $groupByRows = 'url_id';
        $orderByRows = 'COUNT(*)';
        //$queryArray = ['url_id,count(*) as counter', 'sys_dmail_maillog', 'mid=' . intval($row['uid']) . ' AND response_type=2', 'url_id', 'counter'];
        $queryArray = [$fieldRows, $addFieldRows, $tableRows, $whereRows, $groupByRows, $orderByRows];
        $plainUrlsTable = $this->getQueryRows($queryArray, 'url_id');
        
        // Find urls:
        $unpackedMail = unserialize(base64_decode($row['mailContent']));
        // this array will include a unique list of all URLs that are used in the mailing
        $urlArr = [];
        
        $urlMd5Map = [];
        if (is_array($unpackedMail['html']['hrefs'] ?? false)) {
            foreach ($unpackedMail['html']['hrefs'] as $k => $v) {
                // convert &amp; of query params back
                $urlArr[$k] = html_entity_decode($v['absRef']);
                $urlMd5Map[md5($v['absRef'])] = $k;
            }
        }
        if (is_array($unpackedMail['plain']['link_ids'] ?? false)) {
            foreach ($unpackedMail['plain']['link_ids'] as $k => $v) {
                $urlArr[intval(-$k)] = $v;
            }
        }
        
        // Traverse plain urls:
        $mappedPlainUrlsTable = [];
        foreach ($plainUrlsTable as $id => $c) {
            $url = $urlArr[intval($id)];
            if (isset($urlMd5Map[md5($url)])) {
                $mappedPlainUrlsTable[$urlMd5Map[md5($url)]] = $c;
            } else {
                $mappedPlainUrlsTable[$id] = $c;
            }
        }
        
        $urlCounter['total'] = [];
        // Traverse html urls:
        $urlCounter['html'] = [];
        if (count($htmlUrlsTable) > 0) {
            foreach ($htmlUrlsTable as $id => $c) {
                $urlCounter['html'][$id]['counter'] = $urlCounter['total'][$id]['counter'] = $c['counter'];
            }
        }
        
        // Traverse plain urls:
        $urlCounter['plain'] = [];
        foreach ($mappedPlainUrlsTable as $id => $c) {
            // Look up plain url in html urls
            $htmlLinkFound = false;
            foreach ($urlCounter['html'] as $htmlId => $_) {
                if ($urlArr[$id] == $urlArr[$htmlId]) {
                    $urlCounter['html'][$htmlId]['plainId'] = $id;
                    $urlCounter['html'][$htmlId]['plainCounter'] = $c['counter'];
                    $urlCounter['total'][$htmlId]['counter'] = $urlCounter['total'][$htmlId]['counter'] + $c['counter'];
                    $htmlLinkFound = true;
                    break;
                }
            }
            if (!$htmlLinkFound) {
                $urlCounter['plain'][$id]['counter'] = $c['counter'];
                $urlCounter['total'][$id]['counter'] = $urlCounter['total'][$id]['counter'] + $c['counter'];
            }
        }
        
        $tblLines = [];
        $tblLines[] = [
            '',
            $this->getLanguageService()->getLL('stats_total'),
            $this->getLanguageService()->getLL('stats_HTML'),
            $this->getLanguageService()->getLL('stats_plaintext')
        ];
        $tblLines[] = [
            $this->getLanguageService()->getLL('stats_total_responses'),
            ($table['1']['counter'] ?? 0) + ($table['2']['counter'] ?? 0),
            $table['1']['counter'] ?? '0',
            $table['2']['counter'] ?? '0'
        ];
        $tblLines[] = [
            $this->getLanguageService()->getLL('stats_unique_responses'),
            $this->showWithPercent($uniqueHtmlResponses + $uniquePlainResponses, $totalSent), 
            $this->showWithPercent($uniqueHtmlResponses, $htmlSent), 
            $this->showWithPercent($uniquePlainResponses, $plainSent ? $plainSent : $htmlSent)
        ];
        $tblLines[] = [
            $this->getLanguageService()->getLL('stats_links_clicked_per_respondent'),
            ($uniqueHtmlResponses+$uniquePlainResponses ? number_format(($table['1']['counter']+$table['2']['counter'])/($uniqueHtmlResponses+$uniquePlainResponses), 2) : '-'),
            ($uniqueHtmlResponses  ? number_format(($table['1']['counter'])/($uniqueHtmlResponses), 2)  : '-'),
            ($uniquePlainResponses ? number_format(($table['2']['counter'])/($uniquePlainResponses), 2) : '-')
        ];
        
        $output .= '<br /><h2>' . $this->getLanguageService()->getLL('stats_response') . '</h2>';
        $output .= DirectMailUtility::formatTable($tblLines, ['nowrap', 'nowrap', 'nowrap', 'nowrap'], 1, [0, 0, 0, 0]);
        
        arsort($urlCounter['total']);
        arsort($urlCounter['html']);
        arsort($urlCounter['plain']);
        reset($urlCounter['total']);
        
        $tblLines = [];
        $tblLines[] = [
            '',
            $this->getLanguageService()->getLL('stats_HTML_link_nr'),
            $this->getLanguageService()->getLL('stats_plaintext_link_nr'),
            $this->getLanguageService()->getLL('stats_total'),$this->getLanguageService()->getLL('stats_HTML'),
            $this->getLanguageService()->getLL('stats_plaintext'),
            ''
        ];
        
        // HTML mails
        if (intval($row['sendOptions']) & 0x2) {
            $htmlContent = $unpackedMail['html']['content'];
            
            $htmlLinks = [];
            if (is_array($unpackedMail['html']['hrefs'])) {
                foreach ($unpackedMail['html']['hrefs'] as $jumpurlId => $data) {
                    $htmlLinks[$jumpurlId] = [
                        'url'   => $data['ref'],
                        'label' => ''
                    ];
                }
            }
            
            // Parse mail body
            $dom = new \DOMDocument;
            @$dom->loadHTML($htmlContent);
            $links = [];
            // Get all links
            foreach ($dom->getElementsByTagName('a') as $node) {
                $links[] = $node;
            }
            
            // Process all links found
            foreach ($links as $link) {
                /* @var \DOMElement $link */
                $url =  $link->getAttribute('href');
                
                if (empty($url)) {
                    // Drop a tags without href
                    continue;
                }
                
                if (GeneralUtility::isFirstPartOfStr($url, 'mailto:')) {
                    // Drop mail links
                    continue;
                }
                
                $parsedUrl = GeneralUtility::explodeUrl2Array($url);
                
                if (!array_key_exists('jumpurl', $parsedUrl)) {
                    // Ignore non-jumpurl links
                    continue;
                }
                
                $jumpurlId = $parsedUrl['jumpurl'];
                $targetUrl = $htmlLinks[$jumpurlId]['url'];
                
                $title = $link->getAttribute('title');
                
                if (!empty($title)) {
                    // no title attribute
                    $label = '<span title="' . $title . '">' . GeneralUtility::fixed_lgd_cs(substr($targetUrl, -40), 40) . '</span>';
                } else {
                    $label = '<span title="' . $targetUrl . '">' . GeneralUtility::fixed_lgd_cs(substr($targetUrl, -40), 40) . '</span>';
                }
                
                $htmlLinks[$jumpurlId]['label'] = $label;
            }
        }
        
        foreach ($urlCounter['total'] as $id => $_) {
            // $id is the jumpurl ID
            $origId = $id;
            $id     = abs(intval($id));
            $url    = $htmlLinks[$id]['url'] ? $htmlLinks[$id]['url'] : $urlArr[$origId];
            // a link to this host?
            $uParts = @parse_url($url);
            $urlstr = $this->getUrlStr($uParts);
            
            $label = $this->getLinkLabel($url, $urlstr, false, $htmlLinks[$id]['label']);
            
            $img = '<a href="' . $urlstr . '" target="_blank">' .  $this->iconFactory->getIcon('apps-toolbar-menu-search', Icon::SIZE_SMALL) . '</a>';
            
            if (isset($urlCounter['html'][$id]['plainId'])) {
                $tblLines[] = [
                    $label,
                    $id,
                    $urlCounter['html'][$id]['plainId'],
                    $urlCounter['total'][$origId]['counter'],
                    $urlCounter['html'][$id]['counter'],
                    $urlCounter['html'][$id]['plainCounter'],
                    $img
                ];
            } else {
                $html = (empty($urlCounter['html'][$id]['counter']) ? 0 : 1);
                $tblLines[] = [
                    $label,
                    ($html ? $id : '-'),
                    ($html ? '-' : $id),
                    ($html ? $urlCounter['html'][$id]['counter'] : $urlCounter['plain'][$origId]['counter']),
                    $urlCounter['html'][$id]['counter'],
                    $urlCounter['plain'][$origId]['counter'],
                    $img
                ];
            }
        }
        
        
        // go through all links that were not clicked yet and that have a label
        $clickedLinks = array_keys($urlCounter['total']);
        foreach ($urlArr as $id => $link) {
            if (!in_array($id, $clickedLinks) && (isset($htmlLinks['id']))) {
                // a link to this host?
                $uParts = @parse_url($link);
                $urlstr = $this->getUrlStr($uParts);
                
                $label = $htmlLinks[$id]['label'] . ' (' . ($urlstr ? $urlstr : '/') . ')';
                $img = '<a href="' . htmlspecialchars($link) . '" target="_blank">' .  $this->iconFactory->getIcon('apps-toolbar-menu-search', Icon::SIZE_SMALL) . '</a>';
                $tblLines[] = [
                    $label,
                    ($html ? $id : '-'),
                    ($html ? '-' : abs($id)),
                    ($html ? $urlCounter['html'][$id]['counter'] : $urlCounter['plain'][$id]['counter']),
                    $urlCounter['html'][$id]['counter'],
                    $urlCounter['plain'][$id]['counter'],
                    $img
                ];
            }
        }
        
        if ($urlCounter['total']) {
            $output .= '<br /><h2>' . $this->getLanguageService()->getLL('stats_response_link') . '</h2>';
            
            /**
             * Hook for cmd_stats_linkResponses
             */
            if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['direct_mail']['mod4']['cmd_stats_linkResponses'])) {
                $hookObjectsArr = [];
                foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['direct_mail']['mod4']['cmd_stats_linkResponses'] as $classRef) {
                    $hookObjectsArr[] = GeneralUtility::makeInstance($classRef);
                }
                
                foreach ($hookObjectsArr as $hookObj) {
                    if (method_exists($hookObj, 'cmd_stats_linkResponses')) {
                        $output .= $hookObj->cmd_stats_linkResponses($tblLines, $this);
                    }
                }
            } else {
                $output .= DirectMailUtility::formatTable($tblLines, ['nowrap', 'nowrap width="100"', 'nowrap width="100"', 'nowrap', 'nowrap', 'nowrap', 'nowrap'], 1, [1, 0, 0, 0, 0, 0, 1]);
            }
        }

        // ******************
        // Returned mails
        // ******************
        
        // The icons:
        $listIcons = $this->iconFactory->getIcon('actions-system-list-open', Icon::SIZE_SMALL);
        $csvIcons  = $this->iconFactory->getIcon('actions-document-export-csv', Icon::SIZE_SMALL);
        $hideIcons = $this->iconFactory->getIcon('actions-lock', Icon::SIZE_SMALL);
        
        // Icons mails returned
        $iconsMailReturned[] = '<a href="' . $thisurl . '&returnList=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_list_returned') . '"> ' . $listIcons . '</span></a>';
        $iconsMailReturned[] = '<a href="' . $thisurl . '&returnDisable=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_disable_returned') . '"> ' . $hideIcons . '</span></a>';
        $iconsMailReturned[] = '<a href="' . $thisurl . '&returnCSV=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_CSV_returned') . '"> ' . $csvIcons . '</span></a>';
        
        // Icons unknown recip
        $iconsUnknownRecip[] = '<a href="' . $thisurl . '&unknownList=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_list_returned_unknown_recipient') . '"> ' . $listIcons . '</span></a>';
        $iconsUnknownRecip[] = '<a href="' . $thisurl . '&unknownDisable=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_disable_returned_unknown_recipient') . '"> ' . $hideIcons . '</span></a>';
        $iconsUnknownRecip[] = '<a href="' . $thisurl . '&unknownCSV=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_CSV_returned_unknown_recipient') . '"> ' . $csvIcons . '</span></a>';
        
        // Icons mailbox full
        $iconsMailbox[] = '<a href="' . $thisurl . '&fullList=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_list_returned_mailbox_full') . '"> ' . $listIcons . '</span></a>';
        $iconsMailbox[] = '<a href="' . $thisurl . '&fullDisable=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_disable_returned_mailbox_full') . '"> ' . $hideIcons . '</span></a>';
        $iconsMailbox[] = '<a href="' . $thisurl . '&fullCSV=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_CSV_returned_mailbox_full') . '"> ' . $csvIcons . '</span></a>';
        
        // Icons bad host
        $iconsBadhost[] = '<a href="' . $thisurl . '&badHostList=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_list_returned_bad_host') . '"> ' . $listIcons . '</span></a>';
        $iconsBadhost[] = '<a href="' . $thisurl . '&badHostDisable=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_disable_returned_bad_host') . '"> ' . $hideIcons . '</span></a>';
        $iconsBadhost[] = '<a href="' . $thisurl . '&badHostCSV=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_CSV_returned_bad_host') . '"> ' . $csvIcons . '</span></a>';
        
        // Icons bad header
        $iconsBadheader[] = '<a href="' . $thisurl . '&badHeaderList=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_list_returned_bad_header') . '"> ' . $listIcons . '</span></a>';
        $iconsBadheader[] = '<a href="' . $thisurl . '&badHeaderDisable=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_disable_returned_bad_header') . '"> ' . $hideIcons . '</span></a>';
        $iconsBadheader[] = '<a href="' . $thisurl . '&badHeaderCSV=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_CSV_returned_bad_header') . '"> ' . $csvIcons . '</span></a>';
        
        // Icons unknown reasons
        // TODO: link to show all reason
        $iconsUnknownReason[] = '<a href="' . $thisurl . '&reasonUnknownList=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_list_returned_reason_unknown') . '"> ' . $listIcons . '</span></a>';
        $iconsUnknownReason[] = '<a href="' . $thisurl . '&reasonUnknownDisable=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_disable_returned_reason_unknown') . '"> ' . $hideIcons . '</span></a>';
        $iconsUnknownReason[] = '<a href="' . $thisurl . '&reasonUnknownCSV=1" class="bubble"><span class="help" title="' . $this->getLanguageService()->getLL('stats_CSV_returned_reason_unknown') . '"> ' . $csvIcons . '</span></a>';
        
        // Table with Icon
        $fieldRows = 'return_code';
        $addFieldRows = '*';
        $tableRows =  'sys_dmail_maillog';
        $whereRows = 'mid=' . intval($row['uid']) . ' AND response_type=-127';
        $groupByRows = 'return_code';
        $orderByRows = '';
        $queryArray = [$fieldRows, $addFieldRows, $tableRows, $whereRows, $groupByRows, $orderByRows];
        //$queryArray = ['COUNT(*) as counter'.','.'return_code', 'sys_dmail_maillog', 'mid=' . intval($row['uid']) . ' AND response_type=-127', 'return_code'];
        $responseResult = $this->getQueryRows($queryArray, 'return_code');
        
        $tblLines = [];
        $tblLines[] = [
            '',
            $this->getLanguageService()->getLL('stats_count'),
            ''
        ];
        $tblLines[] = [
            $this->getLanguageService()->getLL('stats_total_mails_returned'), 
            number_format(intval($table['-127']['counter'] ?? 0)), 
            implode('&nbsp;', $iconsMailReturned)
        ];
        $tblLines[] = [
            $this->getLanguageService()->getLL('stats_recipient_unknown'), 
            $this->showWithPercent(($responseResult['550']['counter'] ?? 0) + ($responseResult['553']['counter'] ?? 0), ($table['-127']['counter'] ?? 0)), 
            implode('&nbsp;', $iconsUnknownRecip)
        ];
        $tblLines[] = [
            $this->getLanguageService()->getLL('stats_mailbox_full'), 
            $this->showWithPercent(($responseResult['551']['counter'] ?? 0), ($table['-127']['counter'] ?? 0)), 
            implode('&nbsp;', $iconsMailbox)
        ];
        $tblLines[] = [
            $this->getLanguageService()->getLL('stats_bad_host'), 
            $this->showWithPercent(($responseResult['552']['counter'] ?? 0), ($table['-127']['counter'] ?? 0)), 
            implode('&nbsp;', $iconsBadhost)
        ];
        $tblLines[] = [
            $this->getLanguageService()->getLL('stats_error_in_header'), 
            $this->showWithPercent(($responseResult['554']['counter'] ?? 0), ($table['-127']['counter'] ?? 0)),
            implode('&nbsp;', $iconsBadheader)
        ];
        $tblLines[] = [
            $this->getLanguageService()->getLL('stats_reason_unkown'), 
            $this->showWithPercent(($responseResult['-1']['counter'] ?? 0), ($table['-127']['counter'] ?? 0)),
            implode('&nbsp;', $iconsUnknownReason)
        ];
        
        $output .= '<br /><h2>' . $this->getLanguageService()->getLL('stats_mails_returned') . '</h2>';
        $output .= DirectMailUtility::formatTable($tblLines, ['nowrap', 'nowrap', ''], 1, [0, 0, 1]);
        
        // Find all returned mail
        if (GeneralUtility::_GP('returnList')||GeneralUtility::_GP('returnDisable')||GeneralUtility::_GP('returnCSV')) {
            $queryBuilder = $this->getQueryBuilder('sys_dmail_maillog');
            $res =  $queryBuilder
            ->select('rid','rtbl','email')
            ->from('sys_dmail_maillog')
            ->add('where','mid=' . intval($row['uid']) .
                ' AND response_type=-127')
                ->execute();
                
                $idLists = [];
                
                while (($rrow = $res->fetch())) {
                    switch ($rrow['rtbl']) {
                        case 't':
                            $idLists['tt_address'][]=$rrow['rid'];
                            break;
                        case 'f':
                            $idLists['fe_users'][]=$rrow['rid'];
                            break;
                        case 'P':
                            $idLists['PLAINLIST'][] = $rrow['email'];
                            break;
                        default:
                            $idLists[$rrow['rtbl']][]=$rrow['rid'];
                    }
                }
                
                if (GeneralUtility::_GP('returnList')) {
                    if (is_array($idLists['tt_address'])) {
                        $output .= '<h3>' . $this->getLanguageService()->getLL('stats_emails') . '</h3>' . DirectMailUtility::getRecordList(DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address'), 'tt_address', $this->id, 1, $this->sys_dmail_uid);
                    }
                    if (is_array($idLists['fe_users'])) {
                        $output .= '<h3>' . $this->getLanguageService()->getLL('stats_website_users') . '</h3>' . DirectMailUtility::getRecordList(DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users'), 'fe_users', $this->id, 1, $this->sys_dmail_uid);
                    }
                    if (is_array($idLists['PLAINLIST'])) {
                        $output .= '<h3>' . $this->getLanguageService()->getLL('stats_plainlist') . '</h3>';
                        $output .= '<ul><li>' . join('</li><li>', $idLists['PLAINLIST']) . '</li></ul>';
                    }
                }
                if (GeneralUtility::_GP('returnDisable')) {
                    if (is_array($idLists['tt_address'])) {
                        $c = $this->disableRecipients(DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address'), 'tt_address');
                        $output .= '<br />' . $c . ' ' . $this->getLanguageService()->getLL('stats_emails_disabled');
                    }
                    if (is_array($idLists['fe_users'])) {
                        $c = $this->disableRecipients(DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users'), 'fe_users');
                        $output .= '<br />' . $c . ' ' . $this->getLanguageService()->getLL('stats_website_users_disabled');
                    }
                }
                if (GeneralUtility::_GP('returnCSV')) {
                    $emails=[];
                    if (is_array($idLists['tt_address'])) {
                        $arr = DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address');
                        foreach ($arr as $v) {
                            $emails[] = $v['email'];
                        }
                    }
                    if (is_array($idLists['fe_users'])) {
                        $arr=DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users');
                        foreach ($arr as $v) {
                            $emails[] = $v['email'];
                        }
                    }
                    if (is_array($idLists['PLAINLIST'])) {
                        $emails = array_merge($emails, $idLists['PLAINLIST']);
                    }
                    $output .= '<br />' . $this->getLanguageService()->getLL('stats_emails_returned_list') . '<br />';
                    $output .= '<textarea style="width:460px;" rows="6" name="nothing">' . LF . htmlspecialchars(implode(LF, $emails)) . '</textarea>';
                }
        }
        
        // Find Unknown Recipient
        if (GeneralUtility::_GP('unknownList')||GeneralUtility::_GP('unknownDisable')||GeneralUtility::_GP('unknownCSV')) {
            $queryBuilder = $this->getQueryBuilder('sys_dmail_maillog');
            $res =  $queryBuilder
            ->select('rid','rtbl','email')
            ->from('sys_dmail_maillog')
            ->add('where','mid=' . intval($row['uid']) .
                ' AND response_type=-127' .
                ' AND (return_code=550 OR return_code=553)')
                ->execute();
                $idLists = [];
                while (($rrow = $res->fetch())) {
                    switch ($rrow['rtbl']) {
                        case 't':
                            $idLists['tt_address'][] = $rrow['rid'];
                            break;
                        case 'f':
                            $idLists['fe_users'][] = $rrow['rid'];
                            break;
                        case 'P':
                            $idLists['PLAINLIST'][] = $rrow['email'];
                            break;
                        default:
                            $idLists[$rrow['rtbl']][] = $rrow['rid'];
                    }
                }
                
                if (GeneralUtility::_GP('unknownList')) {
                    if (is_array($idLists['tt_address'])) {
                        $output .= '<br />' . $this->getLanguageService()->getLL('stats_emails') . '<br />' . DirectMailUtility::getRecordList(DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address'), 'tt_address', $this->id, 1, $this->sys_dmail_uid);
                    }
                    if (is_array($idLists['fe_users'])) {
                        $output .= '<br />' . $this->getLanguageService()->getLL('stats_website_users') . DirectMailUtility::getRecordList(DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users'), 'fe_users', $this->id, 1, $this->sys_dmail_uid);
                    }
                    if (is_array($idLists['PLAINLIST'])) {
                        $output .= '<br />' . $this->getLanguageService()->getLL('stats_plainlist');
                        $output .= '<ul><li>' . join('</li><li>', $idLists['PLAINLIST']) . '</li></ul>';
                    }
                }
                if (GeneralUtility::_GP('unknownDisable')) {
                    if (is_array($idLists['tt_address'])) {
                        $c = $this->disableRecipients(DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address'), 'tt_address');
                        $output .= '<br />' . $c . ' ' . $this->getLanguageService()->getLL('stats_emails_disabled');
                    }
                    if (is_array($idLists['fe_users'])) {
                        $c = $this->disableRecipients(DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users'), 'fe_users');
                        $output .= '<br />' . $c . ' ' . $this->getLanguageService()->getLL('stats_website_users_disabled');
                    }
                }
                if (GeneralUtility::_GP('unknownCSV')) {
                    $emails = [];
                    if (is_array($idLists['tt_address'])) {
                        $arr = DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address');
                        foreach ($arr as $v) {
                            $emails[] = $v['email'];
                        }
                    }
                    if (is_array($idLists['fe_users'])) {
                        $arr=DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users');
                        foreach ($arr as $v) {
                            $emails[] = $v['email'];
                        }
                    }
                    if (is_array($idLists['PLAINLIST'])) {
                        $emails = array_merge($emails, $idLists['PLAINLIST']);
                    }
                    $output .= '<br />' . $this->getLanguageService()->getLL('stats_emails_returned_unknown_recipient_list') . '<br />';
                    $output .= '<textarea style="width:460px;" rows="6" name="nothing">' . LF . htmlspecialchars(implode(LF, $emails)) . '</textarea>';
                }
        }
        
        // Mailbox Full
        if (GeneralUtility::_GP('fullList')||GeneralUtility::_GP('fullDisable')||GeneralUtility::_GP('fullCSV')) {
            $queryBuilder = $this->getQueryBuilder('sys_dmail_maillog');
            $res =  $queryBuilder
            ->select('rid','rtbl','email')
            ->from('sys_dmail_maillog')
            ->add('where','mid=' . intval($row['uid']) .
                ' AND response_type=-127' .
                ' AND return_code=551')
                ->execute();
                $idLists = [];
                while (($rrow = $res->fetch())) {
                    switch ($rrow['rtbl']) {
                        case 't':
                            $idLists['tt_address'][]=$rrow['rid'];
                            break;
                        case 'f':
                            $idLists['fe_users'][]=$rrow['rid'];
                            break;
                        case 'P':
                            $idLists['PLAINLIST'][] = $rrow['email'];
                            break;
                        default:
                            $idLists[$rrow['rtbl']][]=$rrow['rid'];
                    }
                }
                
                if (GeneralUtility::_GP('fullList')) {
                    if (is_array($idLists['tt_address'])) {
                        $output .= '<br />' . $this->getLanguageService()->getLL('stats_emails') . '<br />' . DirectMailUtility::getRecordList(DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address'), 'tt_address', $this->id, 1, $this->sys_dmail_uid);
                    }
                    if (is_array($idLists['fe_users'])) {
                        $output .= '<br />' . $this->getLanguageService()->getLL('stats_website_users') . DirectMailUtility::getRecordList(DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users'), 'fe_users', $this->id, 1, $this->sys_dmail_uid);
                    }
                    if (is_array($idLists['PLAINLIST'])) {
                        $output .= '<br />' . $this->getLanguageService()->getLL('stats_plainlist');
                        $output .= '<ul><li>' . join('</li><li>', $idLists['PLAINLIST']) . '</li></ul>';
                    }
                }
                if (GeneralUtility::_GP('fullDisable')) {
                    if (is_array($idLists['tt_address'])) {
                        $c = $this->disableRecipients(DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address'), 'tt_address');
                        $output .= '<br />' . $c . ' ' . $this->getLanguageService()->getLL('stats_emails_disabled');
                    }
                    if (is_array($idLists['fe_users'])) {
                        $c = $this->disableRecipients(DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users'), 'fe_users');
                        $output .= '<br />' . $c . ' ' . $this->getLanguageService()->getLL('stats_website_users_disabled');
                    }
                }
                if (GeneralUtility::_GP('fullCSV')) {
                    $emails=[];
                    if (is_array($idLists['tt_address'])) {
                        $arr = DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address');
                        foreach ($arr as $v) {
                            $emails[] = $v['email'];
                        }
                    }
                    if (is_array($idLists['fe_users'])) {
                        $arr = DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users');
                        foreach ($arr as $v) {
                            $emails[] = $v['email'];
                        }
                    }
                    if (is_array($idLists['PLAINLIST'])) {
                        $emails = array_merge($emails, $idLists['PLAINLIST']);
                    }
                    $output .= '<br />' . $this->getLanguageService()->getLL('stats_emails_returned_mailbox_full_list') . '<br />';
                    $output .= '<textarea style="width:460px;" rows="6" name="nothing">' . LF . htmlspecialchars(implode(LF, $emails)) . '</textarea>';
                }
        }
        
        // find Bad Host
        if (GeneralUtility::_GP('badHostList')||GeneralUtility::_GP('badHostDisable')||GeneralUtility::_GP('badHostCSV')) {
            $queryBuilder = $this->getQueryBuilder('sys_dmail_maillog');
            $res =  $queryBuilder
            ->select('rid','rtbl','email')
            ->from('sys_dmail_maillog')
            ->add('where','mid=' . intval($row['uid']) .
                ' AND response_type=-127' .
                ' AND return_code=552')
                ->execute();
                $idLists = [];
                while (($rrow = $res->fetch())) {
                    switch ($rrow['rtbl']) {
                        case 't':
                            $idLists['tt_address'][] = $rrow['rid'];
                            break;
                        case 'f':
                            $idLists['fe_users'][] = $rrow['rid'];
                            break;
                        case 'P':
                            $idLists['PLAINLIST'][] = $rrow['email'];
                            break;
                        default:
                            $idLists[$rrow['rtbl']][] = $rrow['rid'];
                    }
                }
                
                if (GeneralUtility::_GP('badHostList')) {
                    if (is_array($idLists['tt_address'])) {
                        $output .= '<br />' . $this->getLanguageService()->getLL('stats_emails') . '<br />' . DirectMailUtility::getRecordList(DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address'), 'tt_address', $this->id, 1, $this->sys_dmail_uid);
                    }
                    if (is_array($idLists['fe_users'])) {
                        $output .= '<br />' . $this->getLanguageService()->getLL('stats_website_users') . DirectMailUtility::getRecordList(DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users'), 'fe_users', $this->id, 1, $this->sys_dmail_uid);
                    }
                    if (is_array($idLists['PLAINLIST'])) {
                        $output .= '<br />' . $this->getLanguageService()->getLL('stats_plainlist');
                        $output .= '<ul><li>' . join('</li><li>', $idLists['PLAINLIST']) . '</li></ul>';
                    }
                }
                if (GeneralUtility::_GP('badHostDisable')) {
                    if (is_array($idLists['tt_address'])) {
                        $c = $this->disableRecipients(DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address'), 'tt_address');
                        $output .= '<br />' . $c . ' ' . $this->getLanguageService()->getLL('stats_emails_disabled');
                    }
                    if (is_array($idLists['fe_users'])) {
                        $c = $this->disableRecipients(DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users'), 'fe_users');
                        $output .= '<br />' . $c . ' ' . $this->getLanguageService()->getLL('stats_website_users_disabled');
                    }
                }
                if (GeneralUtility::_GP('badHostCSV')) {
                    $emails = [];
                    if (is_array($idLists['tt_address'])) {
                        $arr = DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address');
                        foreach ($arr as $v) {
                            $emails[] = $v['email'];
                        }
                    }
                    if (is_array($idLists['fe_users'])) {
                        $arr = DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users');
                        foreach ($arr as $v) {
                            $emails[] = $v['email'];
                        }
                    }
                    if (is_array($idLists['PLAINLIST'])) {
                        $emails = array_merge($emails, $idLists['PLAINLIST']);
                    }
                    $output .= '<br />' . $this->getLanguageService()->getLL('stats_emails_returned_bad_host_list') . '<br />';
                    $output .= '<textarea style="width:460px;" rows="6" name="nothing">' . LF . htmlspecialchars(implode(LF, $emails)) . '</textarea>';
                }
        }
        
        // find Bad Header
        if (GeneralUtility::_GP('badHeaderList')||GeneralUtility::_GP('badHeaderDisable')||GeneralUtility::_GP('badHeaderCSV')) {
            $queryBuilder = $this->getQueryBuilder('sys_dmail_maillog');
            $res =  $queryBuilder
            ->select('rid','rtbl','email')
            ->from('sys_dmail_maillog')
            ->add('where','mid=' . intval($row['uid']) .
                ' AND response_type=-127' .
                ' AND return_code=554')
                ->execute();
                
                $idLists = [];
                while (($rrow = $res->fetch())) {
                    switch ($rrow['rtbl']) {
                        case 't':
                            $idLists['tt_address'][] = $rrow['rid'];
                            break;
                        case 'f':
                            $idLists['fe_users'][] = $rrow['rid'];
                            break;
                        case 'P':
                            $idLists['PLAINLIST'][] = $rrow['email'];
                            break;
                        default:
                            $idLists[$rrow['rtbl']][] = $rrow['rid'];
                    }
                }
                
                if (GeneralUtility::_GP('badHeaderList')) {
                    if (is_array($idLists['tt_address'])) {
                        $output .= '<br />' . $this->getLanguageService()->getLL('stats_emails') . '<br />' . DirectMailUtility::getRecordList(DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address'), 'tt_address', $this->id, 1, $this->sys_dmail_uid);
                    }
                    if (is_array($idLists['fe_users'])) {
                        $output .= '<br />' . $this->getLanguageService()->getLL('stats_website_users') . DirectMailUtility::getRecordList(DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users'), 'fe_users', $this->id, 1, $this->sys_dmail_uid);
                    }
                    if (is_array($idLists['PLAINLIST'])) {
                        $output .= '<br />' . $this->getLanguageService()->getLL('stats_plainlist');
                        $output .= '<ul><li>' . join('</li><li>', $idLists['PLAINLIST']) . '</li></ul>';
                    }
                }
                
                if (GeneralUtility::_GP('badHeaderDisable')) {
                    if (is_array($idLists['tt_address'])) {
                        $c = $this->disableRecipients(DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address'), 'tt_address');
                        $output .= '<br />' . $c . ' ' . $this->getLanguageService()->getLL('stats_emails_disabled');
                    }
                    if (is_array($idLists['fe_users'])) {
                        $c = $this->disableRecipients(DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users'), 'fe_users');
                        $output .= '<br />' . $c . ' ' . $this->getLanguageService()->getLL('stats_website_users_disabled');
                    }
                }
                if (GeneralUtility::_GP('badHeaderCSV')) {
                    $emails = [];
                    if (is_array($idLists['tt_address'])) {
                        $arr = DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address');
                        foreach ($arr as $v) {
                            $emails[] = $v['email'];
                        }
                    }
                    if (is_array($idLists['fe_users'])) {
                        $arr = DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users');
                        foreach ($arr as $v) {
                            $emails[] = $v['email'];
                        }
                    }
                    if (is_array($idLists['PLAINLIST'])) {
                        $emails = array_merge($emails, $idLists['PLAINLIST']);
                    }
                    $output .= '<br />' . $this->getLanguageService()->getLL('stats_emails_returned_bad_header_list') .  '<br />';
                    $output .= '<textarea style="width:460px;" rows="6" name="nothing">' . LF . htmlspecialchars(implode(LF, $emails)) . '</textarea>';
                }
        }
        
        // find Unknown Reasons
        // TODO: list all reason
        if (GeneralUtility::_GP('reasonUnknownList')||GeneralUtility::_GP('reasonUnknownDisable')||GeneralUtility::_GP('reasonUnknownCSV')) {
            $queryBuilder = $this->getQueryBuilder('sys_dmail_maillog');
            $res =  $queryBuilder
            ->select('rid','rtbl','email')
            ->from('sys_dmail_maillog')
            ->add('where','mid=' . intval($row['uid']) .
                ' AND response_type=-127' .
                ' AND return_code=-1')
                ->execute();
                $idLists = [];
                while (($rrow = $res->fetch())) {
                    switch ($rrow['rtbl']) {
                        case 't':
                            $idLists['tt_address'][] = $rrow['rid'];
                            break;
                        case 'f':
                            $idLists['fe_users'][] = $rrow['rid'];
                            break;
                        case 'P':
                            $idLists['PLAINLIST'][] = $rrow['email'];
                            break;
                        default:
                            $idLists[$rrow['rtbl']][] = $rrow['rid'];
                    }
                }
                
                if (GeneralUtility::_GP('reasonUnknownList')) {
                    if (is_array($idLists['tt_address'])) {
                        $output .= '<br />' . $this->getLanguageService()->getLL('stats_emails') . '<br />' . DirectMailUtility::getRecordList(DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address'), 'tt_address', $this->id, 1, $this->sys_dmail_uid);
                    }
                    if (is_array($idLists['fe_users'])) {
                        $output .= '<br />' . $this->getLanguageService()->getLL('stats_website_users') . DirectMailUtility::getRecordList(DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users'), 'fe_users', $this->id, 1, $this->sys_dmail_uid);
                    }
                    if (is_array($idLists['PLAINLIST'])) {
                        $output .= '<br />' . $this->getLanguageService()->getLL('stats_plainlist');
                        $output .= '<ul><li>' . join('</li><li>', $idLists['PLAINLIST']) . '</li></ul>';
                    }
                }
                if (GeneralUtility::_GP('reasonUnknownDisable')) {
                    if (is_array($idLists['tt_address'])) {
                        $c = $this->disableRecipients(DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address'), 'tt_address');
                        $output .= '<br />' . $c . ' ' . $this->getLanguageService()->getLL('stats_emails_disabled');
                    }
                    if (is_array($idLists['fe_users'])) {
                        $c = $this->disableRecipients(DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users'), 'fe_users');
                        $output .= '<br />' . $c . ' ' . $this->getLanguageService()->getLL('stats_website_users_disabled');
                    }
                }
                if (GeneralUtility::_GP('reasonUnknownCSV')) {
                    $emails = [];
                    if (is_array($idLists['tt_address'])) {
                        $arr = DirectMailUtility::fetchRecordsListValues($idLists['tt_address'], 'tt_address');
                        foreach ($arr as $v) {
                            $emails[] = $v['email'];
                        }
                    }
                    if (is_array($idLists['fe_users'])) {
                        $arr = DirectMailUtility::fetchRecordsListValues($idLists['fe_users'], 'fe_users');
                        foreach ($arr as $v) {
                            $emails[] = $v['email'];
                        }
                    }
                    if (is_array($idLists['PLAINLIST'])) {
                        $emails = array_merge($emails, $idLists['PLAINLIST']);
                    }
                    $output .= '<br />' . $this->getLanguageService()->getLL('stats_emails_returned_reason_unknown_list') . '<br />';
                    $output .= '<textarea style="width:460px;" rows="6" name="nothing">' . LF . htmlspecialchars(implode(LF, $emails)) . '</textarea>';
                }
        }
        
        /**
         * Hook for cmd_stats_postProcess
         * insert a link to open extended importer
         */
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['direct_mail']['mod4']['cmd_stats'] ?? false)) {
            $hookObjectsArr = [];
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['direct_mail']['mod4']['cmd_stats'] as $classRef) {
                $hookObjectsArr[] = GeneralUtility::makeInstance($classRef);
            }
            
            // assigned $output to class property to make it acesssible inside hook
            $this->output = $output;
            
            // and clear the former $output to collect hoot return code there
            $output = '';
            
            foreach ($hookObjectsArr as $hookObj) {
                if (method_exists($hookObj, 'cmd_stats_postProcess')) {
                    $output .= $hookObj->cmd_stats_postProcess($row, $this);
                }
            }
        }
        
        $this->noView = 1;

        return ['out' => $output, 'compactView' => $compactView, 'thisurl' => $thisurl];
    }
    
    /**
     * Wrap a string with a link
     *
     * @param string $str String to be wrapped with a link
     * @param int $uid Record uid to be link
     * @param string $aTitle Title param of the link tag
     *
     * @return string wrapped string as a link
     * @throws RouteNotFoundException If the named route doesn't exist
     */
    protected function linkDMail_record($str, $uid, $aTitle='')
    {
        $moduleUrl = $this->buildUriFromRoute(
            $this->moduleName,
            [
                'id' => $this->id,
                'sys_dmail_uid' => $uid,
                'cmd' => 'stats',
                'SET[dmail_mode]' => 'direct'
            ]
        );
        return '<a title="' . htmlspecialchars($aTitle) . '" href="' . $moduleUrl . '">' . htmlspecialchars($str) . '</a>';
    }
    
    /**
     * Set up URL variables for this $row.
     *
     * @param array $row DB records
     *
     * @return void
     */
    protected function setURLs(array $row)
    {
        // Finding the domain to use
        $this->urlbase = DirectMailUtility::getUrlBase((int)$row['page']);
        
        // Finding the url to fetch content from
        switch ((string)$row['type']) {
            case 1:
                $this->url_html = $row['HTMLParams'];
                $this->url_plain = $row['plainParams'];
                break;
            default:
                $this->url_html = $this->urlbase . '?id=' . $row['page'] . $row['HTMLParams'];
                $this->url_plain = $this->urlbase . '?id=' . $row['page'] . $row['plainParams'];
        }
        
        // plain
        if (!($row['sendOptions']&1) || !$this->url_plain) {
            $this->url_plain = '';
        } else {
            $urlParts = @parse_url($this->url_plain);
            if (!$urlParts['scheme']) {
                $this->url_plain = 'http://' . $this->url_plain;
            }
        }
        
        // html
        if (!($row['sendOptions']&2) || !$this->url_html) {
            $this->url_html = '';
        } else {
            $urlParts = @parse_url($this->url_html);
            if (!$urlParts['scheme']) {
                $this->url_html = 'http://' . $this->url_html;
            }
        }
    }
    
    /**
     * count total recipient from the query_info
     */
    protected function countTotalRecipientFromQueryInfo(string $queryInfo): int
    {
        $totalRecip = 0;
        $idLists = unserialize($queryInfo);
        if(is_array($idLists)) {
            foreach ($idLists['id_lists'] as $idArray) {
                $totalRecip += count($idArray);
            }
        }
        return $totalRecip;
    }
    
    /**
     * Show the compact information of a direct mail record
     *
     * @param array $row Direct mail record
     *
     * @return string The compact infos of the direct mail record
     */
    protected function directMail_compactView($row)
    {
        $dmailInfo = '';
        // Render record:
        if ($row['type']) {
            $dmailData = $row['plainParams'] . ', ' . $row['HTMLParams'];
        } else {
            $page = BackendUtility::getRecord('pages', $row['page'], 'title');
            $dmailData = $row['page'] . ', ' . htmlspecialchars($page['title']);
            $dmailInfo = DirectMailUtility::fName('plainParams') . ' ' . htmlspecialchars($row['plainParams'] . LF . DirectMailUtility::fName('HTMLParams') . $row['HTMLParams']) . '; ' . LF;
        }

        $res = GeneralUtility::makeInstance(SysDmailMaillogRepository::class)->selectSysDmailMaillogsCompactView($row['uid']);
        
        $data = [
            'icon'          => $this->iconFactory->getIconForRecord('sys_dmail', $row, Icon::SIZE_SMALL)->render(),
            'iconInfo'      => $this->iconFactory->getIcon('actions-document-info', Icon::SIZE_SMALL)->render(),
            'subject'       => htmlspecialchars($row['subject']),
            'from_name'     => htmlspecialchars($row['from_name']),
            'from_email'    => htmlspecialchars($row['from_email']),
            'replyto_name'  => htmlspecialchars($row['replyto_name']),
            'replyto_email' => htmlspecialchars($row['replyto_email']),
            'type'          => BackendUtility::getProcessedValue('sys_dmail', 'type', $row['type']),
            'dmailData'     => $dmailData,
            'dmailInfo'     => $dmailInfo,
            'priority'      => BackendUtility::getProcessedValue('sys_dmail', 'priority', $row['priority']),
            'encoding'      => BackendUtility::getProcessedValue('sys_dmail', 'encoding', $row['encoding']),
            'charset'       => BackendUtility::getProcessedValue('sys_dmail', 'charset', $row['charset']),
            'sendOptions'   => BackendUtility::getProcessedValue('sys_dmail', 'sendOptions', $row['sendOptions']) . ($row['attachment'] ? '; ' : ''),
            'attachment'    => BackendUtility::getProcessedValue('sys_dmail', 'attachment', $row['attachment']),
            'flowedFormat'  => BackendUtility::getProcessedValue('sys_dmail', 'flowedFormat', $row['flowedFormat']),
            'includeMedia'  => BackendUtility::getProcessedValue('sys_dmail', 'includeMedia', $row['includeMedia']),
            'delBegin'      => $row['scheduled_begin'] ? BackendUtility::datetime($row['scheduled_begin']) : '-',
            'delEnd'        => $row['scheduled_end'] ? BackendUtility::datetime($row['scheduled_begin']) : '-',
            'totalRecip'    => $this->countTotalRecipientFromQueryInfo($row['query_info']),
            'sentRecip'     => count($res),
            'organisation'  => htmlspecialchars($row['organisation']),
            'return_path'   => htmlspecialchars($row['return_path'])
        ];
        return $data;
    }
    
    /**
     * Make a select query
     *
     * @param array $queryArray Part of select-statement in an array
     * @param string $fieldName DB fieldname to be the array keys
     *
     * @return array Result of the Select-query
     */
    protected function getQueryRows(array $queryArray, $fieldName)
    {
        $queryBuilder = $this->getQueryBuilder($queryArray[2]);
        
        if (empty($queryArray[5])){
            $res = $queryBuilder
            ->count($queryArray[1])
            ->addSelect($queryArray[0])
            ->from($queryArray[2])
            ->add('where',$queryArray[3])
            ->groupBy($queryArray[4])
            ->execute()
            ->fetchAll();
        }else{
            $res = $queryBuilder
            ->count($queryArray[1])
            ->addSelect($queryArray[0])
            ->from($queryArray[2])
            ->add('where',$queryArray[3])
            ->groupBy($queryArray[4])
            ->orderBy($queryArray[5])
            ->execute()
            ->fetchAll();
        }

        /*questa funzione viene chiamata per cambiare la key 'COUNT(*)' in 'counter'*/
        $res = $this->changekeyname($res,'counter','COUNT(*)');
        
        $lines = [];
        foreach($res as $row){
            if ($fieldName) {
                $lines[$row[$fieldName]] = $row;
            } else {
                $lines[] = $row;
            }
        }
        return $lines;
    }
    
    /**
     * Switch the key of an array
     *
     * @return $array
     */
    private function changekeyname($array, $newkey, $oldkey)
    {
        foreach ($array as $key => $value)
        {
            if (is_array($value)) {
                $array[$key] = $this->changekeyname($value,$newkey,$oldkey);
            }
            else {
                $array[$newkey] =  $array[$oldkey];
            }
        }
        unset($array[$oldkey]);
        return $array;
    }
    
    /**
     * Make a percent from the given parameters
     *
     * @param int $pieces Number of pieces
     * @param int $total Total of pieces
     *
     * @return string show number of pieces and the percent
     */
    protected function showWithPercent($pieces, $total)
    {
        $total = intval($total);
        $str = $pieces ? number_format(intval($pieces)) : '0';
        if ($total) {
            $str .= ' / ' . number_format(($pieces/$total*100), 2) . '%';
        }
        return $str;
    }
    
    /**
     * Write the statistic to a temporary table
     *
     * @param array $mrow DB mail records
     *
     * @return void
     */
    protected function makeStatTempTableContent(array $mrow)
    {
        // Remove old:
        
        $connection = $this->getConnection('cache_sys_dmail_stat');
        $connection->delete(
            'cache_sys_dmail_stat', // from
            [ 'mid' => intval($mrow['uid']) ] // where
        );
        
        $queryBuilder = $this->getQueryBuilder('sys_dmail_maillog');
        $res = $queryBuilder
        ->select('rid','rtbl','tstamp','response_type','url_id','html_sent','size')
        ->from('sys_dmail_maillog')
        ->add('where', 'mid=' . intval($mrow['uid']))
        ->orderBy('rtbl')
        ->addOrderBy('rid')
        ->addOrderBy('tstamp')
        ->execute();
        
        $currentRec = '';
        $recRec = [];

        while (($row = $res->fetch())) {
            $thisRecPointer = $row['rtbl'] . $row['rid'];
            
            if ($thisRecPointer != $currentRec) {
                $recRec = [
                    'mid'            => intval($mrow['uid']),
                    'rid'            => $row['rid'],
                    'rtbl'            => $row['rtbl'],
                    'pings'            => [],
                    'plain_links'    => [],
                    'html_links'    => [],
                    'response'        => [],
                    'links'            => []
                ];
                $currentRec = $thisRecPointer;
            }
            switch ($row['response_type']) {
                case '-1':
                    $recRec['pings'][] = $row['tstamp'];
                    $recRec['response'][] = $row['tstamp'];
                    break;
                case '0':
                    $recRec['recieved_html'] = $row['html_sent']&1;
                    $recRec['recieved_plain'] = $row['html_sent']&2;
                    $recRec['size'] = $row['size'];
                    $recRec['tstamp'] = $row['tstamp'];
                    break;
                case '1':
                    // treat html links like plain text
                case '2':
                    // plain text link response
                    $recRec[($row['response_type']==1?'html_links':'plain_links')][] = $row['tstamp'];
                    $recRec['links'][] = $row['tstamp'];
                    if (!$recRec['firstlink']) {
                        $recRec['firstlink'] = $row['url_id'];
                        $recRec['firstlink_time'] = intval(@max($recRec['pings']));
                        $recRec['firstlink_time'] = $recRec['firstlink_time'] ? $row['tstamp']-$recRec['firstlink_time'] : 0;
                    } elseif (!$recRec['secondlink']) {
                        $recRec['secondlink'] = $row['url_id'];
                        $recRec['secondlink_time'] = intval(@max($recRec['pings']));
                        $recRec['secondlink_time'] = $recRec['secondlink_time'] ? $row['tstamp']-$recRec['secondlink_time'] : 0;
                    } elseif (!$recRec['thirdlink']) {
                        $recRec['thirdlink'] = $row['url_id'];
                        $recRec['thirdlink_time'] = intval(@max($recRec['pings']));
                        $recRec['thirdlink_time'] = $recRec['thirdlink_time'] ? $row['tstamp']-$recRec['thirdlink_time'] : 0;
                    }
                    $recRec['response'][] = $row['tstamp'];
                    break;
                case '-127':
                    $recRec['returned'] = 1;
                    break;
                default:
                    // do nothing
            }
        }
        
        $this->storeRecRec($recRec);
    }
    
    /**
     * Insert statistic to a temporary table
     *
     * @param array $recRec Statistic array
     *
     * @return void
     */
    protected function storeRecRec(array $recRec)
    {
        if (is_array($recRec)) {
            $recRec['pings_first'] = empty($recRec['pings']) ? 0 : intval(@min($recRec['pings']));
            $recRec['pings_last']  = empty($recRec['pings']) ? 0 : intval(@max($recRec['pings']));
            $recRec['pings'] = count($recRec['pings']);
            
            $recRec['html_links_first'] = empty($recRec['html_links']) ? 0 : intval(@min($recRec['html_links']));
            $recRec['html_links_last']  = empty($recRec['html_links']) ? 0 : intval(@max($recRec['html_links']));
            $recRec['html_links'] = count($recRec['html_links']);
            
            $recRec['plain_links_first'] = empty($recRec['plain_links']) ? 0 : intval(@min($recRec['plain_links']));
            $recRec['plain_links_last']  = empty($recRec['plain_links']) ? 0 : intval(@max($recRec['plain_links']));
            $recRec['plain_links'] = count($recRec['plain_links']);
            
            $recRec['links_first'] = empty($recRec['links']) ? 0 : intval(@min($recRec['links']));
            $recRec['links_last']  = empty($recRec['links']) ? 0 : intval(@max($recRec['links']));
            $recRec['links'] = count($recRec['links']);
            
            $recRec['response_first'] = DirectMailUtility::intInRangeWrapper((int)((int)(empty($recRec['response']) ? 0 : @min($recRec['response']))-$recRec['tstamp']), 0);
            $recRec['response_last']  = DirectMailUtility::intInRangeWrapper((int)((int)(empty($recRec['response']) ? 0 : @max($recRec['response']))-$recRec['tstamp']), 0);
            $recRec['response'] = count($recRec['response']);
            
            $recRec['time_firstping'] = DirectMailUtility::intInRangeWrapper((int)($recRec['pings_first']-$recRec['tstamp']), 0);
            $recRec['time_lastping']  = DirectMailUtility::intInRangeWrapper((int)($recRec['pings_last']-$recRec['tstamp']), 0);
            
            $recRec['time_first_link'] = DirectMailUtility::intInRangeWrapper((int)($recRec['links_first']-$recRec['tstamp']), 0);
            $recRec['time_last_link']  = DirectMailUtility::intInRangeWrapper((int)($recRec['links_last']-$recRec['tstamp']), 0);
            
            $connection = $this->getConnection('cache_sys_dmail_stat');
            $connection->insert(
                'cache_sys_dmail_stat',
                $recRec
            );
        }
    }
}