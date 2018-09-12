<?php

class PrincePDF extends SpecialPage {

	function __construct() {
		parent::__construct( 'PrincePDF' );
	}

	function execute( $query ) {
		global $wgPrincePDFDirectory;

		$this->setHeaders();
		if ( $query == null ) {
			return;
		}
		$pageName = $query;
		$fileName = str_replace( '/', '-', $pageName );
		$pdfFileName = $fileName . ".pdf";
		$htmlFileName = $fileName . ".html";

		// Set file path
		$pdfFilePath = $wgPrincePDFDirectory . $pdfFileName;
		$htmlFilePath = $wgPrincePDFDirectory . $htmlFileName;
		$pdfDownloadPath = $pdfFilePath;

		// Prepare tmp file
		$tmpPdfBody = $wgPrincePDFDirectory . uniqid('tmpPdfBody') . '.html';
		$tmpFile = $wgPrincePDFDirectory . uniqid('tmpPdfHeader') . '.html';

		$thisTitle = Title::newFromText( $pageName );

		$documentTitle = $pageName;
		$documentSubtitle = '';

		$titles = array( array( $thisTitle, null ) );

		$isManual = false;

		// Special handling for the MintyDocs extension.
		if ( class_exists( 'MintyDocsUtils' ) ) {
			$mdPage = MintyDocsUtils::pageFactory( $thisTitle );
			$mdType = $mdPage->getPageTypeValue();
			if ( $mdType == 'Topic' ) {
				$documentTitle = $mdPage->getManual()->getDisplayName();
				$documentSubtitle = $mdPage->getDisplayName();
			} elseif ( $mdType == 'Manual' ) {
				$isManual = true;
				$documentTitle = $mdPage->getDisplayName();
				$documentSubtitle = '';
				$tocArray = $mdPage->getTableOfContentsArray( true );
				foreach ( $tocArray as $tocLine ) {
					if ( is_string( $tocLine[0] ) ) {
						continue;
					}
					$titles[] = array( $tocLine[0]->getTitle(), $tocLine[0]->getDisplayName() );
				}
			}
		}

		if ( $isManual ) {
			$htmlTemplate = __DIR__ . "/Prince/templates/pdf-template-book.html";
		} else {
			$htmlTemplate = __DIR__ . "/Prince/templates/pdf-template-topic.html";
		}

		$document = new DOMDocument();
		// The commented-out parts can only be used if the PHP LIBXML library is included.
		$document->loadHTMLFile( $htmlTemplate/*, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD*/);

		if ( $isManual ) {
			$tocElement = $document->getElementById( 'pdf-toc-table' );
		}

		$out = $this->getOutput();
		$completeHTML = '';

		foreach( $titles as $i => $curTitleArray ) {
			list( $curTitle, $pageName ) = $curTitleArray;
			$curArticle = new Article( $curTitle, 0 );
			$articleContent = $curArticle->fetchContent();
			$out->setTitle( $curTitle );
			$pageHTML = is_null( $pageName ) ? '' : "<h1 id=\"" . $curTitle->getPrefixedText() . "\">$pageName</h1>\n";
			$pageHTML .= $out->parse( $articleContent );

			$pageHTML = preg_replace( '/<a [^>]*>([^<]*)<\/a>/', '$1', $pageHTML );

			// Is this a good idea? Seems to be necessary for some <a> and <img>
			// tags, and possibly other tags.
			$pageHTML = html_entity_decode( $pageHTML );
			$pageHTML = preg_replace( '/<iframe .*lucidchart\.com\/documents\/embeddedchart\/([^"]*)" <\/iframe>/mis', '<img src="https://www.lucidchart.com/documents/image/$1/1/800" />', $pageHTML );
			$pageHTML = str_replace( '/images-usecases', '/u01/app/apache/htdocs/images-usecases', $pageHTML );
			$pageHTML = ' <pdf-article> ' . $pageHTML . ' </pdf-article> ';
			//create new DomDocument with the article
			$pageHTML = self::shapeContent($pageHTML, $curTitle->getPrefixedText(), '0', '', '', '', '');
			$completeHTML .= $pageHTML;
		}

		file_put_contents($tmpPdfBody, "<pdf-contents> $completeHTML </pdf-contents>", FILE_APPEND);

		$htmlHeader = '<!DOCTYPE html><html lang="' . $wgLanguageCode . '" dir="' . $pageDirection . '"><head><meta http-equiv="content-type" content="text/html; charset="utf-8" /></head><body>';

		$document->getElementById( 'book-title' )->nodeValue = $documentTitle;
		$document->getElementById( 'solution-name' )->nodeValue = $documentSubtitle;


		if ( $isManual ) {
			foreach ( $tocArray as $tocLine ) {
				if ( is_string( $tocLine[0] ) ) {
					$lineText = $tocLine[0];
					$lineURL = '';
				} else {
					$lineText = $tocLine[0]->getDisplayName();
					$lineURL = $tocLine[0]->getTitle()->getPrefixedText();
				}
				$level = $tocLine[1];
				$tocTitle = $document->importNode( self::buildTocTitle( $document, $level, $lineText, $lineURL ), true );
				$tocElement->appendChild( $tocTitle );
			}
		}

		file_put_contents( $tmpFile, $html, FILE_APPEND );
		file_put_contents( $tmpFile, $document->saveHTML(), FILE_APPEND );
		//merge tmp file and tmpbody
		//Linux
		system( "cat $tmpFile $tmpPdfBody > $htmlFilePath" );
		//Windows [todo remove]
		//system("type $tmpFile $tmpPdfBody > $htmlFilePath");
		file_put_contents( $htmlFilePath, '</body></html>', FILE_APPEND );

		$pdf = self::generatePrincePDF( $htmlFilePath, $pdfFilePath );
		if ( $pdf ) {
			self::servePDF( $pdfFilePath, $pdfFileName );
		}

		// Delete the HTML file from the filesystem.
		self::removeTmpFile( $tmpFile );
		self::removeTmpFile( $tmpPdfBody );
		self::removeTmpFile( $htmlFilePath );
	}

	private static function removeTmpFile( $file ) {
		@unlink( $file );
		if ( file_exists( $file ) ) {
			// @TODO - log error message.
			return false;
		}
		return true;

	}

	/**
	 * Removes a cached PDF file.
	 * Just attempts to unlink.
	 * However, does a quick check to see if the file exists after the unlink, and logs if so.
	 *
	 * @param $product string the short name of the product to remove
	 * @param $manual string The short name of the manual to remove
	 * @param $version string The version of the manual to remove
	 * @param $lang language of the file to remove
	 * @return boolean TRUE on success and FALSE on failure
	 */
	public static function removeCachedFile( $product, $manual, $version, $lang ) {
		global $wgPrincePDFDirectory;

		$pdfFileName = "$wgPrincePDFDirectory$lang-$product-$version-$manual-book.pdf";
		if ( file_exists( $pdfFileName ) ) {
			@unlink($pdfFileName);
			// If it still exists after unlinking, oops
			if (file_exists($pdfFileName)) {
				wfDebugLog('PrincePDF', "[ERROR] [PrincePDF::removeCachedFile] Failed to delete cached pdf file $pdfFileName");
				return false;
			}
		} else {
			 wfDebugLog('PrincePDF', "[INFO] [PrincePDF::removeCachedFile] File $pdfFileName doesn't exist");
		}
		return true;
	}

	/**
	 * Serves out a PDF file to the browser
	 *
	 * @param $fileName string The full path to the PDF file.
	 */
	private static function servePDF( $fileName, $pdfFileName ) {
		if ( file_exists( $fileName ) ) {
			header("Content-Type: application/pdf");
			header("Content-Disposition: attachment; filename=$pdfFileName");
			//If content length not defined, can't serve big sizepdf
			//header("Content-Length: " . filesize($fileName));
			readfile($fileName);
			// End processing right away.
			//die();
		} else {
			return false;
		}
	}

	private static function generatePrincePDF( $html, $pdfFilePath ) {
		global $wgPrincePDFPath, $wgPrincePDFCSSFile, $wgPrincePDFJSFile;

		$prince = new Prince( $wgPrincePDFPath );
		$prince->setInputType( 'html' );
		$prince->setHTML( 1 );
		$prince->addStyleSheet( $wgPrincePDFCSSFile );
		$prince->addScript( $wgPrincePDFJSFile );
		$prince->setInsecure( true );

		if ( $prince->convert_file_to_file( $html, $pdfFilePath, $errors ) ) {
			return true;
		} else {
			wfDebugLog('PrincePDF', "Prince generation error " . print_r($errors, true));
			return false;
		}

	}

	private static function buildTocTitle($document, $level, $name, $href) {
		$tocTemplate = __DIR__ . "/Prince/templates/toc-template.html";
		$x = new DOMDocument();
		$x->loadHTMLFile($tocTemplate);
		$e = $x->getElementsByTagName('tr')->item(0);
		$link = $x->getElementsByTagName('a')->item(0);
		$cell = $x->getElementsByTagName('td')->item(0);

		if ( $href == '' ) {
			$cell->removeChild( $link );
			$header = $x->createElement('span', $name);
			$cell->appendChild( $header );
			//return $e;
		} else {
			$link->setAttribute('href', "#" . $href);
			$x->getElementsByTagName('span')->item(0)->nodeValue = $name;
		}

		switch ($level) {
			case '1':
				$tdClass = 'toc-chapter';
				break;
			case '2':
				$tdClass = 'toc-topic';
				break;
			case '3':
				$tdClass = 'toc-sub-topic';
				break;
			default:
				$tdClass = 'toc-chapter';
				break;
		}
		$cell->setAttribute('class', $tdClass);
		return $e;
	}

	private static function shapeContent( $content, $prefixedText, $level, $productName, $versionName, $manualName, $action ) {
		$doc = new DomDocument( '1.0', 'UTF-8' );
		$doc->encoding = 'utf-8';
		$doc->loadHTML( mb_convert_encoding( $content, 'HTML-ENTITIES', 'UTF-8' ) /*, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD*/); //Commented since LIBXML is not compiled with our php instance
		self::removeElements( $doc, $action );
		//self::replaceIds($doc, $prefixedText);
		self::setHeadingsBookmark( $doc, $level, $prefixedText );
		//self::manageLinks($doc, $productName, $versionName, $manualName, $prefixedText);
		self::manageImages( $doc );
		self::manageToggleDisplay( $doc );
		self::manageTabber( $doc );
		self::manageVideos( $doc );
		$html = $doc->saveHTML();

		// Replace &amp; with & to allow proper encoding.
		$html = str_replace( '&amp;', '&', $html );
		return $html;
	}

	private static function setHeadingsBookmark( $doc, $level, $prefixedText ) {
		//begin content changes
		//set fist H1 for intramanual link
		//$doc->getElementsByTagName('h1')->item(0)->setAttribute('id', $prefixedText);
		$headingTags = array( 'h2', 'h3', 'h4', 'h5', 'h6', 'h7', 'h8' );

		//set correct css for h* tags
		switch ($level) {
			case '0':
				if ($doc->getElementsByTagName('h1')->length) {
					$h1 = $doc->getElementsByTagName('h1')->item(0);
					$h1->setAttribute('class', 'chapter');
					//Check design doc from astadia??
					$element = $doc->createElement('div');
					$new_h1 = $h1->cloneNode(true);
					$element->appendChild($new_h1);
					$h1->parentNode->replaceChild($element, $h1);
				}
				break;
			case '1':
				if ($doc->getElementsByTagName('h1')->length) {
					$doc->getElementsByTagName('h1')->item(0)->setAttribute('class', 'topic');
				}
				break;
			case '2':
				if ($doc->getElementsByTagName('h1')->length) {
					$doc->getElementsByTagName('h1')->item(0)->setAttribute('class', 'sub-topic');
				}
				break;
			default:
				# code...
				$headingTags = array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'h7', 'h8');
				break;
		}
		foreach ( $headingTags as $h ) {
			if ( $doc->getElementsByTagName( $h )->length ) {
				$curElems = $doc->getElementsByTagName( $h );
				for ( $i = 0; $i < $curElems->length; $i++ ) {
					$curElems->item( $i )->setAttribute( 'class', 'no-bookmark' );
				}
			}
		}
	}

	private static function replaceIds( $doc, $prefixedText ) {
		$xpath = new DOMXPath( $doc );
		foreach ( $xpath->query( "//*[@id]") as $e ) {
			$thisId = $e->getAttribute( 'id' );
			$e->setAttribute('id', $prefixedText . ':' . $thisId );
		}
	}

	private static function manageLinks($doc, $productName, $versionName, $manualName, $prefixedText) {
		global $wgServer;

		$links = $doc->getElementsByTagName('a');
		$length = $links->length;
		$index = 0;
		for ($i = 0; $i < $length; $i++) {
			$href = $links->item($index)->getAttribute('href');
			$deleteHref = true;

			//Lightboxes links
			//replace Lightbox text
			if (preg_match("/#inline-.*/", $href)) {
				// create new span element with lightboxtext class
				$span = $doc->createElement('span');
				$span->setAttribute('class', 'lightboxtext');
				$span->nodeValue = $links->item($index)->nodeValue;
				//Replace this lightbox with span version
				$links->item($index)->parentNode->replaceChild($span, $links->item($index));
				continue;
			}

			//remove href attribute if href value doesn't start with #
			if ( $href[0] == "#" ) {
				preg_match("/#(.*)/", $href, $thisPageName);
				if ( $thisPageName && $thisPageName[1] ) {
					$links->item( $index )->setAttribute( 'href', '#' . $prefixedText . ':' . $thisPageName[1] );
				}
				$index++;
				continue;
			}

			//replace Toggle Display links
			$id = $links->item($index)->getAttribute('id');
			if (preg_match("/toggledisplay(\d+)/", $id, $matches)) {
				// create new span element with toggleheading class
				$span = $doc->createElement('span');
				$span->setAttribute('class', 'toggleheading');
				$span->nodeValue = $links->item($index)->nodeValue;
				//Replace this toggleheading with span version
				$links->item($index)->parentNode->replaceChild($span, $links->item($index));
				continue;
			}


			if ( $deleteHref ) {
				$links->item($index)->removeAttribute('href');
				$index++;
			}
		}
		return $links;
	}

	private static function manageImages( $doc ) {
		global $wgServer, $wgPrincePDFImagePath;

		$wikiDomain = parse_url($wgServer, PHP_URL_HOST);

		$images = $doc->getElementsByTagName( 'img' );
		for ( $i = 0; $i < $images->length; $i++ ) {
			//5 - make images have absolute URLs
			if ( $images->item( $i )->hasAttribute( 'src' ) ) {
				$src = $images->item( $i )->getAttribute( 'src' );
				preg_match( "/$wikiDomain(\/images.*)/", $src, $match );
				//ex: match[1] = /images/7/78/WM_813_decline.png
				if ( $match[1] ) {
					$images->item( $i )->setAttribute( 'src', $wgPrincePDFImagePath . $match[1] );
				} else {
					$images->item( $i )->setAttribute( 'src', $wgPrincePDFImagePath . $src );
				}
				
			}
			//remove Height attribute
			//OLD 6 - non-printable areas
			if ($images->item($i)->hasAttribute('height')) {
				$images->item($i)->removeAttribute('height');
			}
		}
	}

	private static function removeElements( $doc, $action ) {
		$xpath = new DOMXPath( $doc );
		//Remove
		//- pdf-remove class
		//- noprint
		//- pagination group
		foreach ($xpath->query('//*[contains(attribute::class, "pdf-remove") or contains(attribute::class, "noprint") or contains(attribute::class, "pagination group")]') as $e) {
			// Delete this node
			$e->parentNode->removeChild($e);
		}
		// Remove TOC
		if ( $action == 'pdfbook' ) {
			$rtoc = $doc->getElementById('toc');
			if ($rtoc) {
				$rtoc->parentNode->removeChild($rtoc);
			}

			foreach ($xpath->query('//*[contains(attribute::class, "toc")]') as $e) {
				// Delete this node
				$e->parentNode->removeChild($e);
			}

		}
		//remove comments
		foreach ($xpath->query('//comment()') as $comment) {
			$comment->parentNode->removeChild($comment);
		}

		$tags = array('script', 'link');
		foreach ($tags as $tag) {
			$tags = $doc->getElementsByTagName($tag);
			for ($i = 0; $i < $tags->length; $i++) {
				$tag = $tags->item(0);
				$tags->item(0)->parentNode->removeChild($tag);
			}
		}

	}

	private static function manageToggleDisplay( $doc ) {
		$xpath = new DOMXPath( $doc );
		foreach ( $xpath->query( "//div[contains(@id, 'toggledisplay')]" ) as $e ) {
			$e->removeAttribute( 'id' );
			$e->removeAttribute( 'style' );
		}
	}

	private static function manageTabber( $doc ) {
		$xpath = new DOMXPath($doc);
		foreach ($xpath->query("//div[contains(@class, 'verttabbertab') or contains(@class, 'tabbertab')]") as $e) {
			//create h2 element
			$h2 = $doc->createElement('h2');
			$h2->setAttribute('class', 'no-bookmark');
			//get Title
			$h2->nodeValue = $e->getAttribute('title');
			//insert first child
			$e->insertBefore($h2, $e->firstChild);
		}
	}

	private static function manageVideos( $doc ) {
		$iframes = $doc->getElementsByTagName('iframe');
		$length = $iframes->length;
		for ( $i = 0; $i < $length; $i++ ) {
			$videoLink = $doc->createElement('a');
			$videoLink->setAttribute('href', $iframes->item(0)->getAttribute('src'));
			$videoLink->setAttribute('target', '_blank');
			$videoLink->setAttribute('class', 'pdf-video-link');
			$videoLink->nodeValue = 'Link to video';
			//When we replace an element it is removed from the $iframes list that's why we use 0.
			$iframes->item(0)->parentNode->replaceChild($videoLink, $iframes->item(0));
		}
	}
}
