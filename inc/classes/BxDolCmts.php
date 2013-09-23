<?php defined('BX_DOL') or die('hack attempt');
/**
 * Copyright (c) BoonEx Pty Limited - http://www.boonex.com/
 * CC-BY License - http://creativecommons.org/licenses/by/3.0/
 *
 * @defgroup    DolphinCore Dolphin Core
 * @{
 */

bx_import('BxDolCmtsQuery');

define('BX_OLD_CMT_VOTES', 365*86400); ///< comment votes older than this number of seconds will be deleted automatically 

define('BX_CMT_DISPLAY_FLAT', 'flat');
define('BX_CMT_DISPLAY_THREADED', 'threaded');

define('BX_CMT_BROWSE_HEAD', 'head');
define('BX_CMT_BROWSE_TAIL', 'tail');
define('BX_CMT_BROWSE_POPULAR', 'popular');
define('BX_CMT_BROWSE_CONNECTION', 'connection');

define('BX_CMT_ORDER_BY_DATE', 'date');
define('BX_CMT_ORDER_BY_POPULAR', 'popular');
define('BX_CMT_ORDER_BY_CONNECTION', 'connection');

define('BX_CMT_ORDER_WAY_ASC', 'asc');
define('BX_CMT_ORDER_WAY_DESC', 'desc');

define('BX_CMT_PFP_TOP', 'top');
define('BX_CMT_PFP_BOTTOM', 'bottom');
define('BX_CMT_PFP_BOTH', 'both');


/** 
 * @page objects 
 * @section comments Comments
 * @ref BxDolCmts
 */

/**
 * Comments for any content
 *
 * Related classes:
 * - BxDolCmtsQuery - comments database queries
 * - BxBaseCmtsView - comments base representation
 * - BxTemplCmtsView - custom template representation
 *
 * AJAX comments for any content.
 * Self moderated - users rate all comments, and if comment is
 * below viewing treshold it is hidden by default.
 *
 * To add comments section to your module you need to add a record to 'sys_objects_cmts' table:
 *
 * - ID - autoincremented id for internal usage
 * - ObjectName - your unique module name, with vendor prefix, lowercase and spaces are underscored
 * - TableCmts - table name where comments are stored
 * - TableTrack - table name where comments ratings are stored
 * - Nl2br - convert new lines to <br /> on saving
 * - PerView - number of comments on a page
 * - IsRatable - 0 or 1 allow to rate comments or not
 * - ViewingThreshold - comment viewing treshost, if comment is below this number it is hidden by default
 * - IsOn - is this comment object enabled
 * - RootStylePrefix - toot comments style prefix, if you need root comments look different
 * - TriggerTable - table to be updated upon each comment
 * - TriggerFieldId - TriggerTable table field with unique record id of
 * - TriggerFieldComments - TriggerTable table field with comments count, it will be updated automatically upon eaech comment
 * - ClassName - your custom class name if you need to override default class, this class must have the same constructor arguments
 * - ClassFile - file where your ClassName is stored.
 *
 * You can refer to BoonEx modules for sample record in this table.
 *
 *
 *
 * @section example Example of usage:
 * After filling in the table you can show comments section in any place, using the following code:
 *
 * @code
 * bx_import ('BxTemplCmtsView');
 * $o = new BxTemplCmtsView ('value of ObjectName field', $iYourEntryId);
 * if ($o->isEnabled())
 *     echo $o->getCommentsFirst ();
 * @endcode
 *
 * Please note that you never need to use BxDolCmts class directly, use BxTemplCmtsView instead.
 * Also if you override comments class with your own then make it child of BxTemplCmtsView class.
 *
 *
 *
 * @section acl Memberships/ACL:
 * - comments post - ACTION_ID_COMMENTS_POST
 * - comments vote - ACTION_ID_COMMENTS_VOTE
 * - comments edit own - ACTION_ID_COMMENTS_EDIT_OWN
 * - comments remove own - ACTION_ID_COMMENTS_REMOVE_OWN
 *
 *
 *
 * @section alerts Alerts:
 * Alerts type/unit - every module has own type/unit, it equals to ObjectName.
 *
 * The following alerts are rised
 *
 * - commentPost - comment was posted
 *      - $iObjectId - entry id
 *      - $iSenderId - author of comment
 *      - $aExtra['comment_id'] - just added comment id
 *
 * - commentRemoved - comments was removed
 *      - $iObjectId - entry id
 *      - $iSenderId - comment deleter id
 *      - $aExtra['comment_id'] - removed comment id
 *
 * - commentUpdated - comments was updated
 *      - $iObjectId - entry id
 *      - $iSenderId - comment deleter id
 *      - $aExtra['comment_id'] - updated comment id
 *
 * - commentRated - comments was rated
 *      - $iObjectId - entry id
 *      - $iSenderId - comment rater id
 *      - $aExtra['comment_id'] - rated comment id
 *      - $aExtra['rate'] - comment rate 1 or -1
 *
 */
class BxDolCmts extends BxDol
{
	protected $_oQuery = null;
	protected $_sViewUrl = '';
	protected $_sBaseUrl = '';

	protected $_sSystem = 'profile'; ///< current comment system name
    protected $_aSystem = array (); ///< current comments system array
    protected $_iId = 0; ///< obect id to be commented

    protected $_aMarkers = array ();
    protected $_aPostElements = array (); ///< comment submit form elements

    protected $_sDisplayType = '';
    protected $_sDpSessionKey = '';
    protected $_iDpMaxLevel = 0;

    protected $_sBrowseType = '';
    protected $_sBpSessionKey = ''; 
    protected $_aOrder = array();

    protected $_iRememberTime = 2592000;

    /**
     * Constructor
     * $sSystem - comments system name
     * $iId - obect id to be commented
     */
    function BxDolCmts( $sSystem, $iId, $iInit = 1)
    {
        parent::BxDol();

        $this->_aSystems = $this->getSystems();

        $this->_sSystem = $sSystem;
        if(isset($this->_aSystems[$sSystem]))
            $this->_aSystem = $this->_aSystems[$sSystem];
        else
            return;

		$iCmtTextMin = (int)$this->_aSystem['chars_post_min'];
		$iCmtTextMax = (int)$this->_aSystem['chars_post_max'];
		$this->_aPostElements = array (
            'CmtParent' => array ('reg' => '^[0-9]+$', 'msg' => _t('_bad comment parent id')),
            'CmtText' => array ('reg' => '^.{' . $iCmtTextMin . ',' . $iCmtTextMax . '}$', 'msg' => _t('_Please enter n1-n2 characters', $iCmtTextMin, $iCmtTextMax))
        );

        $this->_iDpMaxLevel = (int)$this->_aSystem['number_of_levels'];
        $this->_sDisplayType = $this->_iDpMaxLevel == 0 ? BX_CMT_DISPLAY_FLAT : BX_CMT_DISPLAY_THREADED;
        $this->_sDpSessionKey = 'bx_' . $this->_sSystem . '_dp_';

        $this->_sBrowseType = $this->_aSystem['browse_type'];
        $this->_sBpSessionKey = 'bx_' . $this->_sSystem . '_bp_';
        $this->_aOrder = array(
        	'by' => BX_CMT_ORDER_BY_DATE, 
        	'way' => BX_CMT_ORDER_WAY_ASC
        );

        list($mixedUserBp, $mixedUserDp) = $this->_getUserChoice();
        if(!empty($mixedUserBp))
        	$this->_sBrowseType = $mixedUserBp;

		if(!empty($mixedUserDp))
			$this->_sDisplayType = $mixedUserDp;

        $this->_oQuery = new BxDolCmtsQuery($this);

        $this->_sViewUrl = BX_DOL_URL_ROOT . 'cmts.php';
        $this->_sBaseUrl = $this->_aSystem['base_url'];
        if(get_mb_substr($this->_sBaseUrl, 0, 4) != 'http')
        	$this->_sBaseUrl = BX_DOL_URL_ROOT . $this->_sBaseUrl;

        if ($iInit)
            $this->init($iId);
    }

    function &getSystems ()
    {
        if (!isset($GLOBALS['bx_dol_cmts_systems'])) {
            $GLOBALS['bx_dol_cmts_systems'] = BxDolDb::getInstance()->fromCache('sys_objects_cmts', 'getAllWithKey', '
                SELECT
                    `ID` as `system_id`,
                    `ObjectName` AS `name`,
                    `TableCmts` AS `table_cmts`,
                    `TableTrack` AS `table_track`,
                    `CharsPostMin` AS `chars_post_min`,
                    `CharsPostMax` AS `chars_post_max`,
                    `CharsDisplayMax` AS `chars_display_max`,
                    `Nl2br` AS `nl2br`,
                    `PerView` AS `per_view`,
                    `PerViewReplies` AS `per_view_replies`,
                    `BrowseType` AS `browse_type`,
                    `IsBrowseSwitch` AS `is_browse_switch`,
                    `PostFormPosition` AS `post_form_position`,
                    `NumberOfLevels` AS `number_of_levels`,
                    `IsDisplaySwitch` AS `is_display_switch`,
                    `IsRatable` AS `is_ratable`,
                    `ViewingThreshold` AS `viewing_threshold`,
                    `IsOn` AS `is_on`,
                    `RootStylePrefix` AS `root_style_prefix`,
                    `BaseUrl` AS `base_url`,
                    `TriggerTable` AS `trigger_table`,
                    `TriggerFieldId` AS `trigger_field_id`,
                    `TriggerFieldTitle` AS `trigger_field_title`,
                    `TriggerFieldComments` AS `trigger_field_comments`,
                    `ClassName` AS `class_name`,
                    `ClassFile` AS `class_file`
                FROM `sys_objects_cmts`', 'name');
        }
        return $GLOBALS['bx_dol_cmts_systems'];
    }

    function init ($iId)
    {
        if (!$this->isEnabled()) 
        	return;

        if (empty($this->iId) && $iId)
            $this->setId($iId);

		$this->addMarkers(array(
			'object_id' => $this->getId(),
			'user_id' => $this->_getAuthorId()
		));
    }

    function getId ()
    {
        return $this->_iId;
    }

    function isEnabled ()
    {
        return isset($this->_aSystem['is_on']) && $this->_aSystem['is_on'];
    }

	function getSystemId()
    {
        return $this->_aSystem['system_id'];
    }

    function getSystemName()
    {
        return $this->_sSystem;
    }

	function getSystemInfo()
    {
        return $this->_aSystem;
    }
	function getMaxLevel()
	{
		return $this->_iDpMaxLevel;
	}
    function getOrder ()
    {
        return $this->_sOrder;
    }

	function getPerView ($iCmtParentId = 0)
    {
        return $iCmtParentId == 0 ? $this->_aSystem['per_view'] : $this->_aSystem['per_view_replies'];
    }

    function getBaseUrl()
    {
    	return $this->_replaceMarkers($this->_sBaseUrl);
    }

	function isValidSystem ($sSystem)
    {
        return isset($this->_aSystems[$sSystem]);
    }

    function isNl2br ()
    {
        return $this->_aSystem['nl2br'];
    }

    function isRatable ()
    {
        return $this->_aSystem['is_ratable'];
    }

    /**
     * set id to operate with votes
     */
    function setId ($iId)
    {
        if ($iId == $this->getId()) return;
        $this->_iId = $iId;
    }

    /**
     * it is called on cron every day or similar period to clean old comment votes
     */
    function maintenance () {
        $iDeletedRecords = 0;
        foreach ($this->_aSystems as $aSystem) {
            if (!$aSystem['is_on'])                
                continue;
            $oQuery = new BxDolCmtsQuery($aSystem);
            $iDeletedRecords += $oQuery->maintenance();
            unset($oQuery);
        }
        return $iDeletedRecords;
    }

    /** comments functions
     *********************************************/
    function getCommentsArray ($iVParentId, $aOrder, $iStart = 0, $iCount = -1)
    {
        return $this->_oQuery->getComments ($this->getId(), $iVParentId, $this->_getAuthorId(), $aOrder, $iStart, $iCount);
    }

    function getCommentRow ($iCmtId)
    {
        return $this->_oQuery->getComment ($this->getId(), $iCmtId, $this->_getAuthorId());
    }

    function onObjectDelete ($iObjectId = 0)
    {
        return $this->_oQuery->deleteObjectComments ($iObjectId ? $iObjectId : $this->getId());
    }

    /**
     * delete all profiles comments in all systems, if some replies exist, set this comment to anonymous
     */
    function onAuthorDelete ($iAuthorId)
    {
        for ( reset($this->_aSystems) ; list ($sSystem, $aSystem) = each ($this->_aSystems) ; )
        {
            $oQuery = new BxDolCmtsQuery($aSystem);
            $oQuery->deleteAuthorComments ($iAuthorId);
        }
        return true;
    }

    function getCommentsTableName ()
    {
        return $this->_oQuery->getTableName ();
    }

    function getObjectTitle ($iObjectId = 0)
    {
    	return $this->_oQuery->getObjectTitle ($iObjectId ? $iObjectId : $this->getId());
    }

    function getObjectCommentsCount ($iObjectId = 0)
    {
        return $this->_oQuery->getObjectCommentsCount ($iObjectId ? $iObjectId : $this->getId());
    }
	/**
     * Add replace markers.
     * @param $a array of markers as key => value
     * @return true on success or false on error
     */
    public function addMarkers ($a) {
        if (empty($a) || !is_array($a))
            return false;
        $this->_aMarkers = array_merge ($this->_aMarkers, $a);
        return true;
    }

    /** permissions functions
    *********************************************/
    function checkAction ($iAction, $isPerformAction = false)
    {
        $iId = $this->_getAuthorId();
        $check_res = checkAction($iId, $iAction, $isPerformAction);
        return $check_res[CHECK_ACTION_RESULT] === CHECK_ACTION_RESULT_ALLOWED;
    }

    function checkActionErrorMsg ($iAction)
    {
        $iId = $this->_getAuthorId();
        $check_res = checkAction($iId, $iAction);
        return $check_res[CHECK_ACTION_RESULT] !== CHECK_ACTION_RESULT_ALLOWED ? $check_res[CHECK_ACTION_MESSAGE] : '';
    }

    function isRateAllowed ($isPerformAction = false)
    {
        return $this->checkAction (ACTION_ID_COMMENTS_VOTE, $isPerformAction); 
    }

    function msgErrRateAllowed ()
    { 
        return $this->checkActionErrorMsg(ACTION_ID_COMMENTS_VOTE);
    }

    /**
     * is post comment allowed
     */
    function isPostReplyAllowed ($isPerformAction = false) {
        return $this->checkAction (ACTION_ID_COMMENTS_POST, $isPerformAction);
    }

    function msgErrPostReplyAllowed ()
    {
        return $this->checkActionErrorMsg(ACTION_ID_COMMENTS_POST);
    }

    /**
     * is edit own comment allowed
     */
    function isEditAllowed ($isPerformAction = false)
    {
        return $this->checkAction (ACTION_ID_COMMENTS_EDIT_OWN, $isPerformAction);
    }

    function msgErrEditAllowed ()
    {
        return $this->checkActionErrorMsg (ACTION_ID_COMMENTS_EDIT_OWN);
    }

    /**
     * is removing own comment allowed
     */
    function isRemoveAllowed ($isPerformAction = false)
    {
        return $this->checkAction (ACTION_ID_COMMENTS_REMOVE_OWN, $isPerformAction);
    }

    function msgErrRemoveAllowed ()
    {
        return $this->checkActionErrorMsg(ACTION_ID_COMMENTS_REMOVE_OWN);
    }

    /**
     * is edit any comment allowed
     */
    function isEditAllowedAll ($isPerformAction = false)
    {
        return isAdmin() || $this->checkAction (ACTION_ID_COMMENTS_EDIT_ALL, $isPerformAction) ? true : false;
    }

    /**
     * is removing any comment allowed
     */
    function isRemoveAllowedAll ($isPerformAction = false)
    {
        return isAdmin() || $this->checkAction (ACTION_ID_COMMENTS_REMOVE_ALL, $isPerformAction) ? true : false;
    }

    /**
     * actions functions
     */
    function actionPaginateGet () {
        if (!$this->isEnabled())
           return '';

        $iCmtStart = isset($_REQUEST['CmtStart']) ? bx_process_input($_REQUEST['CmtStart'], BX_DATA_INT) : 0;
        $iCmtNum = isset($_REQUEST['CmtNum']) ? bx_process_input($_REQUEST['CmtNum'], BX_DATA_INT) : -1;
        $iCmtPerPage= isset($_REQUEST['CmtPerPage']) ? bx_process_input($_REQUEST['CmtPerPage'], BX_DATA_INT) : $this->getPerView();

        return $this->getPaginate($iCmtStart, $iCmtNum, $iCmtPerPage);
    }

    function actionFormGet () {
        if (!$this->isEnabled())
           return '';

        $iCmtParentId= isset($_REQUEST['CmtParent']) ? bx_process_input($_REQUEST['CmtParent'], BX_DATA_INT) : 0;
        $sCmtBrowse = isset($_REQUEST['CmtBrowse']) ? bx_process_input($_REQUEST['CmtBrowse'], BX_DATA_TEXT) : '';
        $sCmtDisplay = isset($_REQUEST['CmtDisplay']) ? bx_process_input($_REQUEST['CmtDisplay'], BX_DATA_TEXT) : '';

        return $this->getFormBox(array('parent_id' => $iCmtParentId, 'type' => $sCmtBrowse), array('type' => $sCmtDisplay));
    }

	function actionPlusedByGet () {
        if (!$this->isEnabled())
           return '';

        $iCmtId= isset($_REQUEST['CmtId']) ? bx_process_input($_REQUEST['CmtId'], BX_DATA_INT) : 0;
        return $this->getPlusedBy($iCmtId);
    }

    function actionCmtsGet () {
        if (!$this->isEnabled())
           return '';

        $iCmtVParentId = bx_process_input($_REQUEST['CmtParent'], BX_DATA_INT);
        $iCmtStart = isset($_REQUEST['CmtStart']) ? bx_process_input($_REQUEST['CmtStart'], BX_DATA_INT) : -1;
        $iCmtPerView = isset($_REQUEST['CmtPerView']) ? bx_process_input($_REQUEST['CmtPerView'], BX_DATA_INT) : -1;
        $sCmtBrowse = isset($_REQUEST['CmtBrowse']) ? bx_process_input($_REQUEST['CmtBrowse'], BX_DATA_TEXT) : '';
        $sCmtDisplay = isset($_REQUEST['CmtDisplay']) ? bx_process_input($_REQUEST['CmtDisplay'], BX_DATA_TEXT) : '';

        return $this->getComments(array('vparent_id' => $iCmtVParentId, 'start' => $iCmtStart, 'per_view' => $iCmtPerView, 'type' => $sCmtBrowse), array('type' => $sCmtDisplay));
    }

    function actionCmtGet () {
        if (!$this->isEnabled())
           return '';

        $iCmtId = bx_process_input($_REQUEST['Cmt'], BX_DATA_INT);
        $sCmtBrowse = isset($_REQUEST['CmtBrowse']) ? bx_process_input($_REQUEST['CmtBrowse'], BX_DATA_TEXT) : '';
        $sCmtDisplay = isset($_REQUEST['CmtDisplay']) ? bx_process_input($_REQUEST['CmtDisplay'], BX_DATA_TEXT) : '';

        $aCmt = $this->getCommentRow($iCmtId);
        
        require_once( BX_DIRECTORY_PATH_PLUGINS . 'Services_JSON.php' );
        $oJson = new Services_JSON();

        return $oJson->encode(array(
        	'parent_id' => $aCmt['cmt_parent_id'],
        	'vparent_id' => $aCmt['cmt_vparent_id'],
        	'content' => $this->getComment($aCmt, array('type' => $sCmtBrowse), array('type' => $sCmtDisplay))
        ));
    }

    function actionCmtPost ()
    {
        if(!$this->isEnabled())
        	return '';

        if(!$this->isPostReplyAllowed())
        	return '';

        $iCmtParentId = bx_process_input($_REQUEST['CmtParent'], BX_DATA_INT);
		$iCmtAuthorId = $this->_getAuthorId();

        $sCmtText = bx_process_input($_REQUEST['CmtText']);
        if($this->_isSpam($sCmtText))
            return sprintf(_t("_sys_spam_detected"), BX_DOL_URL_ROOT . 'contact.php');

        $sText = $this->_prepareTextForSave ($sCmtText);

        $iCmtNewId = $this->_oQuery->addComment ($this->getId(), $iCmtParentId, $iCmtAuthorId, $sText);
        if(false === $iCmtNewId)
            return '';

        $this->_triggerComment();

        $this->isPostReplyAllowed(true);

        bx_import('BxDolAlerts');
        $oZ = new BxDolAlerts($this->_sSystem, 'commentPost', $this->getId(), $iCmtAuthorId, array('comment_id' => $iCmtNewId, 'comment_author_id' => $iCmtAuthorId));
        $oZ->alert();

        return $iCmtNewId;
    }



    /**
     * returns error string on error, or empty string on success
     */
    function actionCmtRemove ()
    {
        if (!$this->isEnabled()) return '';

        $iCmtId = bx_process_input($_REQUEST['Cmt'], BX_DATA_INT);
        $aCmt = $this->_oQuery->getCommentSimple ($this->getId(), $iCmtId);

        if (!$aCmt)
            return _t('_No such comment');

        if ($aCmt['cmt_replies'] > 0)
            return _t('_Can not delete comments with replies');

        $isRemoveAllowed = $this->isRemoveAllowedAll() || ($aCmt['cmt_author_id'] == $this->_getAuthorId() && $this->isRemoveAllowed());
        if (!$isRemoveAllowed)
            return $aCmt['cmt_author_id'] == $this->_getAuthorId() && !$this->isRemoveAllowed() ? strip_tags($this->msgErrRemoveAllowed()) : _t('_Access denied');

        if (!$this->_oQuery->removeComment ($this->getId(), $aCmt['cmt_id'], $aCmt['cmt_parent_id']))
            return _t('_Database Error');

        $this->_triggerComment();

        if ($aCmt['cmt_author_id'] == $this->_getAuthorId())
           $this->isRemoveAllowed(true);

        bx_import('BxDolAlerts');
        $oZ = new BxDolAlerts($this->_sSystem, 'commentRemoved', $this->getId(), $this->_getAuthorId(), array('comment_id' => $aCmt['cmt_id'], 'comment_author_id' => $aCmt['cmt_author_id']));
        $oZ->alert();

        return '';
    }



    /**
     * returns string with "err" prefix on error, or string with html form on success
     */
    function actionCmtEdit ()
    {
        if (!$this->isEnabled())
        	return '';

        $iCmtId = bx_process_input($_REQUEST['Cmt'], BX_DATA_INT);

        $aCmt = $this->_oQuery->getCommentSimple ($this->getId(), $iCmtId);
        if (!$aCmt)
            return 'err'._t('_No such comment');

        $isEditAllowed = $this->isEditAllowedAll() || ($aCmt['cmt_author_id'] == $this->_getAuthorId() && $this->isEditAllowed());
        if(!$isEditAllowed)
            return 'err' . ($aCmt['cmt_author_id'] == $this->_getAuthorId() && !$this->isEditAllowed() ? strip_tags($this->msgErrEditAllowed()) : _t('_Access denied'));

        return $this->getForm(0, $this->_prepareTextForEdit($aCmt['cmt_text']), 'cmtUpdate(this, ' . $iCmtId . ')');
    }

    function actionCmtEditSubmit() {

        if (!$this->isEnabled()) return '{}';

        require_once( BX_DIRECTORY_PATH_PLUGINS . 'Services_JSON.php' );
        $oJson = new Services_JSON();

        $iCmtId = bx_process_input($_REQUEST['Cmt'], BX_DATA_INT);
        $sCmtText = bx_process_input($_REQUEST['CmtText']);

        if ($this->_isSpam($sCmtText))
            return $oJson->encode(array('err' => sprintf(_t("_sys_spam_detected"), BX_DOL_URL_ROOT . 'contact.php')));

        $sText = $this->_prepareTextForSave ($sCmtText);

        $aCmt = $this->_oQuery->getCommentSimple ($this->getId(), $iCmtId);
        if(!$aCmt)
            return '{}';

        $isEditAllowed = $this->isEditAllowedAll() || ($aCmt['cmt_author_id'] == $this->_getAuthorId() && $this->isEditAllowed());
        if (!$isEditAllowed)
            return '{}';

        if ($sText != $aCmt['cmt_text'] && $this->_oQuery->updateComment ($this->getId(), $aCmt['cmt_id'], $sText)) {
            if ($aCmt['cmt_author_id'] == $this->_getAuthorId())
               $this->isEditAllowed(true);

            bx_import('BxDolAlerts');
            $oZ = new BxDolAlerts($this->_sSystem, 'commentUpdated', $this->getId(), $this->_getAuthorId(), array('comment_id' => $aCmt['cmt_id'], 'comment_author_id' => $aCmt['cmt_author_id']));
            $oZ->alert();
        }

        $aCmt = $this->_oQuery->getCommentSimple ($this->getId(), $iCmtId);
        return $oJson->encode(array('text' => $aCmt['cmt_text']));
    }

    function actionCmtRate () {
        if (!$this->isEnabled()) return _t('_Error occured');
        if (!$this->isRatable()) return _t('_Error occured');
        if (!$this->isRateAllowed()) return _t('_Access denied');

        $iCmtId = bx_process_input($_REQUEST['Cmt'], BX_DATA_INT);
        $iRate = bx_process_input($_REQUEST['Rate'], BX_DATA_INT);

        if($iRate >= 1)
            $iRate = 1;
        elseif($iRate <= -1)
            $iRate = -1;
        else
            return _t('_Error occured');

        if(!$this->_oQuery->rateComment($this->getSystemId(), $iCmtId, $iRate, $this->_getAuthorId(), $this->_getAuthorIp()))
            return _t('_Duplicate vote');

        $aCmt = $this->_oQuery->getCommentSimple ($this->getId(), $iCmtId);

        $this->isRateAllowed(true);

        bx_import('BxDolAlerts');
        $oZ = new BxDolAlerts($this->_sSystem, 'commentRated', $this->getId(), $this->_getAuthorId(), array('comment_id' => $iCmtId, 'comment_author_id' => $aCmt['cmt_author_id'], 'rate' => $iRate));
        $oZ->alert();

        return '';
    }


    /** private functions
    *********************************************/

    function _getAuthorId ()
    {
        return isMember() ? bx_get_logged_profile_id() : 0;
    }

    function _getAuthorPassword ()
    {
        return isMember() ? $_COOKIE['memberPassword'] : "";
    }

    function _getAuthorIp ()
    {
        return $_SERVER['REMOTE_ADDR'];
    }

	function _getAuthorInfo($iAuthorId = 0)
    {
    	bx_import('BxDolProfile');
		$oProfile = BxDolProfile::getInstance($iAuthorId);
		if (!$oProfile) {
			bx_import('BxDolProfileUndefined');
			$oProfile = BxDolProfileUndefined::getInstance();
		}

		return array(
			$oProfile->getDisplayName(), 
			$oProfile->getUrl(), 
			$oProfile->getIcon(),
			$oProfile->getUnit()
		);
    }

    function _prepareTextForEdit ($s)
    {
        if ($this->isNl2br())
            return str_replace('<br />', "", $s);
        return $s;
    }

    function _prepareTextForSave ($s) 
    {
    	$iDataAction = $this->isNl2br() ? BX_DATA_TEXT_MULTILINE : BX_DATA_TEXT; // TODO: make sure that it is processed before output !
		return bx_process_input($s, $iDataAction);
    }

    function _prepareTextForOutput ($s)
    {
    	$iDataAction = $this->isNl2br() ? BX_DATA_TEXT_MULTILINE : BX_DATA_TEXT;

		$s = bx_process_output($s, $iDataAction);
		$s = preg_replace("/((https?|ftp|news):\/\/)?([a-z]([a-z0-9\-]*\.)+(aero|arpa|biz|com|coop|edu|gov|info|int|jobs|mil|museum|name|nato|net|org|pro|travel|[a-z]{2})|(([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.){3}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5]))(\/[a-z0-9_\-\.~]+)*(\/([a-z0-9_\-\.]*)(\?[a-z0-9+_\-\.%=&amp;]*)?)?(#[a-z][a-z0-9_]*)?/", '<a href="$0" target="_blank">$0</a>', $s);

		return $s; 
    }

	function _prepareParams(&$aBp, &$aDp)
    {
    	$aBp['type'] = isset($aBp['type']) && !empty($aBp['type']) ? $aBp['type'] : $this->_sBrowseType;
    	$aBp['parent_id'] = isset($aBp['parent_id']) ? $aBp['parent_id'] : 0;
    	$aBp['start'] = isset($aBp['start']) ? $aBp['start'] : -1;
    	$aBp['per_view'] = isset($aBp['per_view']) ? $aBp['per_view'] : -1;
    	$aBp['order']['by'] = isset($aBp['order_by']) ? $aBp['order_by'] : $this->_aOrder['by'];
    	$aBp['order']['way'] = isset($aBp['order_way']) ? $aBp['order_way'] : $this->_aOrder['way'];

    	$aDp['type'] = isset($aDp['type']) && !empty($aDp['type']) ? $aDp['type'] : $this->_sDisplayType;

		switch($aDp['type']) {
			case BX_CMT_DISPLAY_FLAT:
				$aBp['vparent_id'] = -1;
				$aBp['per_view'] = $aBp['per_view'] != -1 ? $aBp['per_view'] : $this->getPerView(0);
				break;

			case BX_CMT_DISPLAY_THREADED:
				$aBp['per_view'] = $aBp['per_view'] != -1 ? $aBp['per_view'] : $this->getPerView($aBp['vparent_id']);
				break;
		}

		switch ($aBp['type']) {
			case BX_CMT_BROWSE_POPULAR:
				$aBp['order'] = array(
					'by' => BX_CMT_ORDER_BY_POPULAR,
					'way' => BX_CMT_ORDER_WAY_DESC
				);
				break;

			case BX_CMT_BROWSE_CONNECTION:
				$aBp['order'] = array(
					'by' => BX_CMT_ORDER_BY_CONNECTION,
					'way' => BX_CMT_ORDER_WAY_DESC
				);
				break;
		}

		$aBp['count'] = $this->_oQuery->getCommentsCount($this->_iId, $aBp['vparent_id']);
		if($aBp['start'] != -1)
			return;

		$aBp['start'] = 0;
		if($aBp['type'] == BX_CMT_BROWSE_TAIL) {
			$aBp['start'] = $aBp['count'] - $aBp['per_view'];
			if($aBp['start'] < 0) {
    			$aBp['per_view'] += $aBp['start'];
    			$aBp['start'] = 0;
			}
		}

		$this->_setUserChoice($aBp['type'], $aDp['type']);
    }

    function _triggerComment()
    {
        if (!$this->_aSystem['trigger_table'])
            return false;
        $iId = $this->getId();
        if (!$iId)
            return false;
        $iCount = $this->_oQuery->getObjectCommentsCount ($iId);
        return $this->_oQuery->updateTriggerTable($iId, $iCount);
    }

    function _isSpam($s) {
        return bx_is_spam($s);
    }

	/**
     * Replace provided markers in a string
     * @param $mixed string or array to replace markers in
     * @return string where all occured markers are replaced
     */ 
    protected function _replaceMarkers ($mixed) {
        if (empty($this->_aMarkers))
            return $mixed;

        if (is_array($mixed)) {
            foreach ($mixed as $sKey => $sValue)
                $mixed[$sKey] = $this->_replaceMarkers ($sValue);
        } else {
            foreach ($this->_aMarkers as $sKey => $sValue)
                $mixed = str_replace('{' . $sKey . '}', $sValue, $mixed);
        }

        return $mixed;
    }

    function _getUserChoice()
    {
    	$mixedBp = $mixedDp = false;
    	if(!isLogged())
    		return array($mixedBp, $mixedDp);

    	$iUserId = $this->_getAuthorId();

    	bx_import('BxDolSession');
    	$oSession = BxDolSession::getInstance();

    	$mixedBp = $oSession->getValue($this->_sBpSessionKey . $iUserId);
    	$mixedDp = $oSession->getValue($this->_sDpSessionKey . $iUserId);

    	return array($mixedBp, $mixedDp);
    }

    function _setUserChoice($sBp, $sDp)
    {
    	if(!isLogged())
    		return;

    	$iUserId = $this->_getAuthorId();

    	bx_import('BxDolSession');
    	$oSession = BxDolSession::getInstance();

    	if(!empty($sBp))
	    	$oSession->setValue($this->_sBpSessionKey . $iUserId, $sBp);

    	if(!empty($sDp))
    		$oSession->setValue($this->_sDpSessionKey . $iUserId, $sDp);
    }
}

/** @} */
