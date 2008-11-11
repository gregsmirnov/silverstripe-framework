<?php
/**
 * Standard basic search form which conducts a fulltext search on all {@link SiteTree}
 * objects. 
 * 
 * @see Use ModelController and SearchContext for a more generic search implementation based around DataObject
 * @package sapphire
 * @subpackage search
 */
class SearchForm extends Form {
	
	protected $showInSearchTurnOn;
	
	function __construct($controller, $name, $fields = null, $actions = null, $showInSearchTurnOn = true) {
		$this->showInSearchTurnOn = $showInSearchTurnOn;
		
		if(!$fields) {
			$fields = new FieldSet(
				new TextField('Search', _t('SearchForm.SEARCH', 'Search')
			));
		}
		
		if(!$actions) {
			$actions = new FieldSet(
				new FormAction("getResults", _t('SearchForm.GO', 'Go'))
			);
		}
		
		// We need this because it's a get form.  It can't go in the action value
		// Hayden: Sorry if I've got it mixed up, but on the results or not found pages, the
		// RelativeLink seems to be empty and it packs a sad
		$formController = isset($_GET['formController']) ? $_GET['formController'] : null;		
		if(!$formController) $formController = $controller->RelativeLink();
		
		$fields->push(new HiddenField('formController', null, $formController));
		$fields->push(new HiddenField('executeForm', null, $name));
		
		parent::__construct($controller, $name, $fields, $actions);
		
		$this->disableSecurityToken();
	}
	
	function FormMethod() {
		return "get";
	}
	
	public function forTemplate(){
		return $this->renderWith(array(
			'SearchForm',
			'Form'
		));
	}

	/**
	 * Return dataObjectSet of the results using $_REQUEST to get info from form.
	 * Wraps around {@link searchEngine()}
	 */
	public function getResults($numPerPage = 10){
	 	$keywords = $_REQUEST['Search'];

	 	$andProcessor = create_function('$matches','
	 		return " +" . $matches[2] . " +" . $matches[4] . " ";
	 	');
	 	$notProcessor = create_function('$matches', '
	 		return " -" . $matches[3];
	 	');

	 	$keywords = preg_replace_callback('/()("[^()"]+")( and )("[^"()]+")()/i', $andProcessor, $keywords);
	 	$keywords = preg_replace_callback('/(^| )([^() ]+)( and )([^ ()]+)( |$)/i', $andProcessor, $keywords);
		$keywords = preg_replace_callback('/(^| )(not )("[^"()]+")/i', $notProcessor, $keywords);
		$keywords = preg_replace_callback('/(^| )(not )([^() ]+)( |$)/i', $notProcessor, $keywords);
		
		$keywords = $this->addStarsToKeywords($keywords);

		if(strpos($keywords, '"') !== false || strpos($keywords, '+') !== false || strpos($keywords, '-') !== false || strpos($keywords, '*') !== false) {
			return $this->searchEngine($keywords, $numPerPage, "Relevance DESC", "", true);
		} else {
			return $this->searchEngine($keywords, $numPerPage);
			$sortBy = "Relevance DESC";
		}		
	}

	function addStarsToKeywords($keywords) {
		if(!trim($keywords)) return "";
		// Add * to each keyword
		$splitWords = split(" +" , trim($keywords));
		while(list($i,$word) = each($splitWords)) {
			if($word[0] == '"') {
				while(list($i,$subword) = each($splitWords)) {
					$word .= ' ' . $subword;
					if(substr($subword,-1) == '"') break;
				}
			} else {
				$word .= '*';
			}
			$newWords[] = $word;
		}
		return implode(" ", $newWords);
	}
		
		
	/**
	 * The core search engine, used by this class and its subclasses to do fun stuff.
	 * Searches both SiteTree and File.
	 */
	public function searchEngine($keywords, $numPerPage = 10, $sortBy = "Relevance DESC", $extraFilter = "", $booleanSearch = false, $alternativeFileFilter = "", $invertedMatch = false) {
		$fileFilter = '';	 	
	 	$keywords = addslashes($keywords);
	 	
	 	if($booleanSearch) $boolean = "IN BOOLEAN MODE";
	 	if($extraFilter) {
	 		$extraFilter = " AND $extraFilter";
	 		
	 		if($alternativeFileFilter) $fileFilter = " AND $alternativeFileFilter";
	 		else $fileFilter = $extraFilter;
	 	}
	 	
	 	if($this->showInSearchTurnOn)	$extraFilter .= " AND showInSearch <> 0";

		$start = isset($_GET['start']) ? (int)$_GET['start'] : 0;
		$limit = $start . ", " . (int) $numPerPage;
		
		$notMatch = $invertedMatch ? "NOT " : "";
		if($keywords) {
			$matchContent = "MATCH (Title, MenuTitle, Content, MetaTitle, MetaDescription, MetaKeywords) AGAINST ('$keywords' $boolean)";
			$matchFile = "MATCH (Filename, Title, Content) AGAINST ('$keywords' $boolean) AND ClassName = 'File'";
	
			// We make the relevance search by converting a boolean mode search into a normal one
			$relevanceKeywords = str_replace(array('*','+','-'),'',$keywords);
			$relevanceContent = "MATCH (Title) AGAINST ('$relevanceKeywords') + MATCH (Title, MenuTitle, Content, MetaTitle, MetaDescription, MetaKeywords) AGAINST ('$relevanceKeywords')";
			$relevanceFile = "MATCH (Filename, Title, Content) AGAINST ('$relevanceKeywords')";
		} else {
			$relevanceContent = $relevanceFile = 1;
			$matchContent = $matchFile = "1 = 1";
		}

		$queryContent = singleton('SiteTree')->extendedSQL($notMatch . $matchContent . $extraFilter, "");

		$baseClass = reset($queryContent->from);
		// There's no need to do all that joining
		$queryContent->from = array(str_replace('`','',$baseClass) => $baseClass);
		$queryContent->select = array("ClassName","$baseClass.ID","ParentID","Title","URLSegment","Content","LastEdited","Created","_utf8'' AS Filename", "_utf8'' AS Name", "$relevanceContent AS Relevance");
		$queryContent->orderby = null;

		$queryFiles = singleton('File')->extendedSQL($notMatch . $matchFile . $fileFilter, "");
		$baseClass = reset($queryFiles->from);
		// There's no need to do all that joining
		$queryFiles->from = array(str_replace('`','',$baseClass) => $baseClass);
		$queryFiles->select = array("ClassName","$baseClass.ID","_utf8'' AS ParentID","Title","_utf8'' AS URLSegment","Content","LastEdited","Created","Filename","Name","$relevanceFile AS Relevance");
		$queryFiles->orderby = null;
		
		$fullQuery = $queryContent->sql() . " UNION " . $queryFiles->sql() . " ORDER BY $sortBy LIMIT $limit";
		$totalCount = $queryContent->unlimitedRowCount() + $queryFiles->unlimitedRowCount();
	
		$records = DB::query($fullQuery);

		foreach($records as $record)
			$objects[] = new $record['ClassName']($record);

		if(isset($objects)) $doSet = new DataObjectSet($objects);
		else $doSet = new DataObjectSet();
		
		$doSet->setPageLimits($start, $numPerPage, $totalCount);
		return $doSet;
	}
	
	public function getSearchQuery() {
		return Convert::raw2xml($_REQUEST['Search']);
	}

}

?>