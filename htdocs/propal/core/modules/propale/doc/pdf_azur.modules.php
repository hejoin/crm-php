<?php

/* Copyright (C) 2004-2011 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012 Regis Houssin        <regis@dolibarr.fr>
 * Copyright (C) 2008      Raphael Bertrand     <raphael.bertrand@resultic.fr>
 * Copyright (C) 2010-2012 Juanjo Menent	    <jmenent@2byte.es>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * or see http://www.gnu.org/
 */

/**
 * 	\file       htdocs/core/modules/propale/doc/pdf_azur.modules.php
 * 	\ingroup    propale
 * 	\brief      Fichier de la classe permettant de generer les propales au modele Azur
 */
require_once DOL_DOCUMENT_ROOT . '/propal/core/modules/propale/modules_propale.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
//require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT . '/societe/lib/societe.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php';

/**
 * 	Classe permettant de generer les propales au modele Azur
 */
class pdf_azur extends ModelePDFPropales {

    var $db;
    var $name;
    var $description;
    var $type;
    var $phpmin = array(4, 3, 0); // Minimum version of PHP required by module
    var $version = 'dolibarr';
    var $page_largeur;
    var $page_hauteur;
    var $format;
    var $marge_gauche;
    var $marge_droite;
    var $marge_haute;
    var $marge_basse;
    var $emetteur; // Objet societe qui emet

    /**
     * 	Constructor
     *
     *  @param		DoliDB		$db      Database handler
     */

    function __construct($db) {
        global $conf, $langs, $mysoc;

        $langs->load("main");
        $langs->load("bills");

        $this->db = $db;
        $this->name = "azur";
        $this->description = $langs->trans('DocModelAzurDescription');

        // Dimension page pour format A4
        $this->type = 'pdf';
        $formatarray = pdf_getFormat();
        $this->page_largeur = $formatarray['width'];
        $this->page_hauteur = $formatarray['height'];
        $this->format = array($this->page_largeur, $this->page_hauteur);
        $this->marge_gauche = 10;
        $this->marge_droite = 10;
        $this->marge_haute = 10;
        $this->marge_basse = 10;

        $this->option_logo = 1;                    // Affiche logo
        $this->option_tva = 1;                     // Gere option tva FACTURE_TVAOPTION
        $this->option_modereg = 1;                 // Affiche mode reglement
        $this->option_condreg = 1;                 // Affiche conditions reglement
        $this->option_codeproduitservice = 1;      // Affiche code produit-service
        $this->option_multilang = 1;               // Dispo en plusieurs langues
        $this->option_escompte = 1;                // Affiche si il y a eu escompte
        $this->option_credit_note = 1;             // Support credit notes
        $this->option_freetext = 1;       // Support add of a personalised text
        $this->option_draft_watermark = 1;     //Support add of a watermark on drafts

        $this->franchise = !$mysoc->tva_assuj;

        // Get source company
        $this->emetteur = $mysoc;
        if (!$this->emetteur->country_code)
            $this->emetteur->country_code = substr($langs->defaultlang, -2);    // By default, if was not defined

            
// Defini position des colonnes
        $this->posxdesc = $this->marge_gauche + 1;
        $this->posxtva = 111;
        $this->posxup = 126;
        $this->posxqty = 145;
        $this->posxdiscount = 162;
        $this->postotalht = 174;

        $this->tva = array();
        $this->localtax1 = array();
        $this->localtax2 = array();
        $this->atleastoneratenotnull = 0;
        $this->atleastonediscount = 0;
    }

    /**
     *  Function to build pdf onto disk
     *
     *  @param		Object		$object				Object to generate
     *  @param		Translate	$outputlangs		Lang output object
     *  @param		string		$srctemplatepath	Full path of source filename for generator using a template file
     *  @param		int			$hidedetails		Do not show line details
     *  @param		int			$hidedesc			Do not show desc
     *  @param		int			$hideref			Do not show ref
     *  @param		object		$hookmanager		Hookmanager object
     *  @return     int             				1=OK, 0=KO
     */
    function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0, $hookmanager = false) {
        global $user, $langs, $conf;

        if (!is_object($outputlangs))
            $outputlangs = $langs;
        // For backward compatibility with FPDF, force output charset to ISO, because FPDF expect text to be encoded in ISO
        if (!empty($conf->global->MAIN_USE_FPDF))
            $outputlangs->charset_output = 'ISO-8859-1';

        $outputlangs->load("main");
        $outputlangs->load("dict");
        $outputlangs->load("companies");
        $outputlangs->load("bills");
        $outputlangs->load("propal");
        $outputlangs->load("products");

        $default_font_size = pdf_getPDFFontSize($outputlangs);

        if ($conf->propal->dir_output) {
            $object->fetch_thirdparty();

            // $deja_regle = 0;
            // Definition of $dir and $file
            if ($object->specimen) {
                $dir = $conf->propal->dir_output;
                $file = $dir . "/SPECIMEN.pdf";
            } else {
                $objectref = dol_sanitizeFileName($object->ref);
                $dir = $conf->propal->dir_output . "/" . $objectref;
                $file = $dir . "/" . $objectref . ".pdf";
            }

            if (!file_exists($dir)) {
                if (dol_mkdir($dir) < 0) {
                    $this->error = $langs->trans("ErrorCanNotCreateDir", $dir);
                    return 0;
                }
            }

            if (file_exists($dir)) {
                $nblignes = count($object->lines);

                // Create pdf instance
                $pdf = pdf_getInstance($this->format);
                $heightforinfotot = 50; // Height reserved to output the info and total part
                $heightforfooter = 25; // Height reserved to output the footer (value include bottom margin)
                $pdf->SetAutoPageBreak(1, 0);

                if (class_exists('TCPDF')) {
                    $pdf->setPrintHeader(false);
                    $pdf->setPrintFooter(false);
                }
                $pdf->SetFont(pdf_getPDFFont($outputlangs));
                // Set path to the background PDF File
                if (empty($conf->global->MAIN_DISABLE_FPDI) && !empty($conf->global->MAIN_ADD_PDF_BACKGROUND)) {
                    $pagecount = $pdf->setSourceFile($conf->mycompany->dir_output . '/' . $conf->global->MAIN_ADD_PDF_BACKGROUND);
                    $tplidx = $pdf->importPage(1);
                }

                $pdf->Open();
                $pagenb = 0;
                $pdf->SetDrawColor(128, 128, 128);

                $pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
                $pdf->SetSubject($outputlangs->transnoentities("CommercialProposal"));
                $pdf->SetCreator("Dolibarr " . DOL_VERSION);
                $pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
                $pdf->SetKeyWords($outputlangs->convToOutputCharset($object->ref) . " " . $outputlangs->transnoentities("CommercialProposal"));
                if (!empty($conf->global->MAIN_DISABLE_PDF_COMPRESSION))
                    $pdf->SetCompression(false);

                $pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);   // Left, Top, Right
                // Positionne $this->atleastonediscount si on a au moins une remise
                for ($i = 0; $i < $nblignes; $i++) {
                    if ($object->lines[$i]->remise_percent) {
                        $this->atleastonediscount++;
                    }
                }

                // New page
                $pdf->AddPage();
                if (!empty($tplidx))
                    $pdf->useTemplate($tplidx);
                $pagenb++;
                $this->_pagehead($pdf, $object, 1, $outputlangs, $hookmanager);
                $pdf->SetFont('', '', $default_font_size - 1);
                $pdf->MultiCell(0, 3, '');  // Set interline to 3
                $pdf->SetTextColor(0, 0, 0);

                $tab_top = 90;
                $tab_top_middlepage = (empty($conf->global->MAIN_PDF_DONOTREPEAT_HEAD) ? 42 : 10);
                $tab_top_newpage = (empty($conf->global->MAIN_PDF_DONOTREPEAT_HEAD) ? 42 : 10);
                $tab_height = 130;
                $tab_height_middlepage = 200;
                $tab_height_newpage = 150;

                // Affiche notes
                if (!empty($object->note_public)) {
                    $tab_top = 88;

                    $pdf->SetFont('', '', $default_font_size - 1);
                    $pdf->writeHTMLCell(190, 3, $this->posxdesc - 1, $tab_top, dol_htmlentitiesbr($object->note_public), 0, 1);
                    $nexY = $pdf->GetY();
                    $height_note = $nexY - $tab_top;

                    // Rect prend une longueur en 3eme param
                    $pdf->SetDrawColor(192, 192, 192);
                    $pdf->Rect($this->marge_gauche, $tab_top - 1, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $height_note + 1);

                    $tab_height = $tab_height - $height_note;
                    $tab_top = $nexY + 6;
                } else {
                    $height_note = 0;
                }

                $iniY = $tab_top + 7;
                $curY = $tab_top + 7;
                $nexY = $tab_top + 7;

                // Loop on each lines
                for ($i = 0; $i < $nblignes; $i++) {
                    $curY = $nexY;
                    $pdf->SetFont('', '', $default_font_size - 1);   // Into loop to work with multipage
                    $pdf->SetTextColor(0, 0, 0);

                    $pdf->setTopMargin($tab_top_newpage);
                    $pdf->setPageOrientation('', 1, $this->marge_basse + $heightforfooter + $heightforinfotot); // The only function to edit the bottom margin of current page to set it.
                    $pageposbefore = $pdf->getPage();

                    // Description of product line
                    $curX = $this->posxdesc - 1;
                    pdf_writelinedesc($pdf, $object, $i, $outputlangs, $this->posxtva - $curX, 4, $curX, $curY, $hideref, $hidedesc, 0, $hookmanager);

                    $nexY = $pdf->GetY();
                    $pageposafter = $pdf->getPage();
                    $pdf->setPage($pageposbefore);
                    $pdf->setTopMargin($this->marge_haute);
                    $pdf->setPageOrientation('', 1, 0); // The only function to edit the bottom margin of current page to set it.
                    // We suppose that a too long description is moved completely on next page
                    if ($pageposafter > $pageposbefore) {
                        $pdf->setPage($pageposafter);
                        $curY = $tab_top_newpage;
                    }

                    $pdf->SetFont('', '', $default_font_size - 1);   // On repositionne la police par defaut
                    // VAT Rate
                    if (empty($conf->global->MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT)) {
                        $vat_rate = pdf_getlinevatrate($object, $i, $outputlangs, $hidedetails, $hookmanager);
                        $pdf->SetXY($this->posxtva, $curY);
                        $pdf->MultiCell($this->posxup - $this->posxtva - 1, 4, $vat_rate, 0, 'R');
                    }

                    // Unit price before discount
                    $up_excl_tax = pdf_getlineupexcltax($object, $i, $outputlangs, $hidedetails, $hookmanager);
                    $pdf->SetXY($this->posxup, $curY);
                    $pdf->MultiCell($this->posxqty - $this->posxup - 1, 4, $up_excl_tax, 0, 'R', 0);

                    // Quantity
                    $qty = pdf_getlineqty($object, $i, $outputlangs, $hidedetails, $hookmanager);
                    $pdf->SetXY($this->posxqty, $curY);
                    $pdf->MultiCell($this->posxdiscount - $this->posxqty - 1, 4, $qty, 0, 'R');

                    // Discount on line
                    $pdf->SetXY($this->posxdiscount, $curY);
                    if ($object->lines[$i]->remise_percent) {
                        $remise_percent = pdf_getlineremisepercent($object, $i, $outputlangs, $hidedetails, $hookmanager);
                        $pdf->MultiCell($this->postotalht - $this->posxdiscount - 1, 4, $remise_percent, 0, 'R');
                    }

                    // Total HT line
                    $total_excl_tax = pdf_getlinetotalexcltax($object, $i, $outputlangs, $hidedetails, $hookmanager);
                    $pdf->SetXY($this->postotalht, $curY);
                    $pdf->MultiCell($this->page_largeur - $this->marge_droite - $this->postotalht, 4, $total_excl_tax, 0, 'R', 0);

                    // Collecte des totaux par valeur de tva dans $this->tva["taux"]=total_tva
                    $tvaligne = $object->lines[$i]->total_tva;
                    $localtax1ligne = $object->lines[$i]->total_localtax1;
                    $localtax2ligne = $object->lines[$i]->total_localtax2;

                    if ($object->remise_percent)
                        $tvaligne-=($tvaligne * $object->remise_percent) / 100;
                    if ($object->remise_percent)
                        $localtax1ligne-=($localtax1ligne * $object->remise_percent) / 100;
                    if ($object->remise_percent)
                        $localtax2ligne-=($localtax2ligne * $object->remise_percent) / 100;

                    $vatrate = (string) $object->lines[$i]->tva_tx;
                    $localtax1rate = (string) $object->lines[$i]->localtax1_tx;
                    $localtax2rate = (string) $object->lines[$i]->localtax2_tx;

                    if (($object->lines[$i]->info_bits & 0x01) == 0x01)
                        $vatrate.='*';
                    if (!isset($this->tva[$vatrate]))
                        $this->tva[$vatrate] = '';
                    if (!isset($this->localtax1[$localtax1rate]))
                        $this->localtax1[$localtax1rate] = '';
                    if (!isset($this->localtax2[$localtax2rate]))
                        $this->localtax2[$localtax2rate] = '';
                    $this->tva[$vatrate] += $tvaligne;
                    $this->localtax1[$localtax1rate]+=$localtax1ligne;
                    $this->localtax2[$localtax2rate]+=$localtax2ligne;

                    $nexY+=2;    // Passe espace entre les lignes
                    // Detect if some page were added automatically and output _tableau for past pages
                    while ($pagenb < $pageposafter) {
                        $pdf->setPage($pagenb);
                        if ($pagenb == 1) {
                            $this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforfooter, 0, $outputlangs, 0, 1);
                        } else {
                            $this->_tableau($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforfooter, 0, $outputlangs, 1, 1);
                        }
                        $this->_pagefoot($pdf, $object, $outputlangs);
                        $pagenb++;
                        $pdf->setPage($pagenb);
                        $pdf->setPageOrientation('', 1, 0); // The only function to edit the bottom margin of current page to set it.
                        if (empty($conf->global->MAIN_PDF_DONOTREPEAT_HEAD))
                            $this->_pagehead($pdf, $object, 0, $outputlangs, $hookmanager);
                    }
                    if (isset($object->lines[$i + 1]->pagebreak) && $object->lines[$i + 1]->pagebreak) {
                        if ($pagenb == 1) {
                            $this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforfooter, 0, $outputlangs, 0, 1);
                        } else {
                            $this->_tableau($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforfooter, 0, $outputlangs, 1, 1);
                        }
                        $this->_pagefoot($pdf, $object, $outputlangs);
                        // New page
                        $pdf->AddPage();
                        if (!empty($tplidx))
                            $pdf->useTemplate($tplidx);
                        $pagenb++;
                        if (empty($conf->global->MAIN_PDF_DONOTREPEAT_HEAD))
                            $this->_pagehead($pdf, $object, 0, $outputlangs, $hookmanager);
                    }
                }

                // Show square
                if ($pagenb == 1) {
                    $this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforinfotot - $heightforfooter, 0, $outputlangs, 0, 0);
                    $bottomlasttab = $this->page_hauteur - $heightforinfotot - $heightforfooter + 1;
                } else {
                    $this->_tableau($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforinfotot - $heightforfooter, 0, $outputlangs, 1, 0);
                    $bottomlasttab = $this->page_hauteur - $heightforinfotot - $heightforfooter + 1;
                }

                // Affiche zone infos
                $posy = $this->_tableau_info($pdf, $object, $bottomlasttab, $outputlangs);

                // Affiche zone totaux
                //$posy=$this->_tableau_tot($pdf, $object, $deja_regle, $bottomlasttab, $outputlangs);
                $posy = $this->_tableau_tot($pdf, $object, 0, $bottomlasttab, $outputlangs);

                // Affiche zone versements
                /*
                  if ($deja_regle)
                  {
                  $posy=$this->_tableau_versements($pdf, $object, $posy, $outputlangs);
                  }
                 */

                // Pied de page
                $this->_pagefoot($pdf, $object, $outputlangs);
                $pdf->AliasNbPages();

                $pdf->Close();

                $pdf->Output($file, 'F');

                //Add pdfgeneration hook
                if (!is_object($hookmanager)) {
                    include_once DOL_DOCUMENT_ROOT . '/core/class/hookmanager.class.php';
                    $hookmanager = new HookManager($this->db);
                }
                $hookmanager->initHooks(array('pdfgeneration'));
                $parameters = array('file' => $file, 'object' => $object, 'outputlangs' => $outputlangs);
                global $action;
                $reshook = $hookmanager->executeHooks('afterPDFCreation', $parameters, $this, $action);    // Note that $action and $object may have been modified by some hooks

                if (!empty($conf->global->MAIN_UMASK))
                    @chmod($file, octdec($conf->global->MAIN_UMASK));

                return 1;   // Pas d'erreur
            }
            else {
                $this->error = $langs->trans("ErrorCanNotCreateDir", $dir);
                return 0;
            }
        } else {
            $this->error = $langs->trans("ErrorConstantNotDefined", "PROP_OUTPUTDIR");
            return 0;
        }

        $this->error = $langs->trans("ErrorUnknown");
        return 0;   // Erreur par defaut
    }

    /**
     *  Show payments table
     *
     *  @param	PDF			&$pdf           Object PDF
     *  @param  Object		$object         Object invoice
     *  @param  int			$posy           Position y in PDF
     *  @param  Translate	$outputlangs    Object langs for output
     *  @return int             			<0 if KO, >0 if OK
     */
    function _tableau_versements(&$pdf, $object, $posy, $outputlangs) {
        
    }

    /**
     *   Show miscellaneous information (payment mode, payment term, ...)
     *
     *   @param		PDF			&$pdf     		Object PDF
     *   @param		Object		$object			Object to show
     *   @param		int			$posy			Y
     *   @param		Translate	$outputlangs	Langs object
     *   @return	void
     */
    function _tableau_info(&$pdf, $object, $posy, $outputlangs) {
        global $conf;
        $default_font_size = pdf_getPDFFontSize($outputlangs);

        $pdf->SetFont('', '', $default_font_size - 1);

        // If France, show VAT mention if not applicable
        if ($this->emetteur->pays_code == 'FR' && $this->franchise == 1) {
            $pdf->SetFont('', 'B', $default_font_size - 2);
            $pdf->SetXY($this->marge_gauche, $posy);
            $pdf->MultiCell(100, 3, $outputlangs->transnoentities("VATIsNotUsedForInvoice"), 0, 'L', 0);

            $posy = $pdf->GetY() + 4;
        }

        // Show shipping date
        if (/* isset($object->type) && $object->type != 2 && */ $object->date_livraison) {
            $outputlangs->load("sendings");
            $pdf->SetFont('', 'B', $default_font_size - 2);
            $pdf->SetXY($this->marge_gauche, $posy);
            $titre = $outputlangs->transnoentities("DateDeliveryPlanned") . ':';
            $pdf->MultiCell(80, 4, $titre, 0, 'L');
            $pdf->SetFont('', '', $default_font_size - 2);
            $pdf->SetXY(82, $posy);
            $dlp = dol_print_date($object->date_livraison, "daytext", false, $outputlangs, true);
            $pdf->MultiCell(80, 4, $dlp, 0, 'L');

            $posy = $pdf->GetY() + 1;
        }
//        elseif (isset($object->type) && $object->type != 2 && ($object->availability_code || $object->availability))    // Show availability conditions
        elseif (isset($object->availability_code) && !empty($object->availability_code)) {    // Show availability conditions
            $pdf->SetFont('', 'B', $default_font_size - 2);
            $pdf->SetXY($this->marge_gauche, $posy);
            $titre = $outputlangs->transnoentities("AvailabilityPeriod") . ':';
            $pdf->MultiCell(80, 4, $titre, 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('', '', $default_font_size - 2);
            $pdf->SetXY(82, $posy);
//			$lib_availability=$outputlangs->transnoentities("AvailabilityType".$object->availability_code)!=('AvailabilityType'.$object->availability_code)?$outputlangs->transnoentities("AvailabilityType".$object->availability_code):$outputlangs->convToOutputCharset($object->availability);
            $lib_availability = $outputlangs->transnoentities($object->getExtraFieldLabel('availability_code')) != ($object->getExtraFieldLabel('availability_code')) ? $outputlangs->transnoentities($object->getExtraFieldLabel('availability_code')) : $outputlangs->convToOutputCharset($object->getExtraFieldLabel('availability_code'));
            $lib_availability = str_replace('\n', "\n", $lib_availability);
            $pdf->MultiCell(80, 4, $lib_availability, 0, 'L');

            $posy = $pdf->GetY() + 1;
        }

        // Show payments conditions
        if (/*isset($object->type) && $object->type != 2 && */($object->cond_reglement_code)) {
            $pdf->SetFont('', 'B', $default_font_size - 2);
            $pdf->SetXY($this->marge_gauche, $posy);
            $titre = $outputlangs->transnoentities("PaymentConditions") . ':';
            $pdf->MultiCell(80, 4, $titre, 0, 'L');

            $pdf->SetFont('', '', $default_font_size - 2);
            $pdf->SetXY(52, $posy);
            $lib_condition_paiement = $outputlangs->transnoentities("PaymentCondition" . $object->getExtraFieldLabel('cond_reglement_code')) != ('PaymentCondition' . $object->getExtraFieldLabel('cond_reglement_code')) ? $outputlangs->transnoentities("PaymentCondition" . $object->getExtraFieldLabel('cond_reglement_code')) : $outputlangs->convToOutputCharset($object->getExtraFieldLabel('cond_reglement_code'));
            $lib_condition_paiement = str_replace('\n', "\n", $lib_condition_paiement);
            $pdf->MultiCell(80, 4, $lib_condition_paiement, 0, 'L');

            $posy = $pdf->GetY() + 3;
        }


        if (/*isset($object->type) && $object->type != 2*/ true) {
            // Check a payment mode is defined
            if (empty($object->mode_reglement_code)
                    && !$conf->global->FACTURE_CHQ_NUMBER
                    && !$conf->global->FACTURE_RIB_NUMBER) {
                $pdf->SetXY($this->marge_gauche, $posy);
                $pdf->SetTextColor(200, 0, 0);
                $pdf->SetFont('', 'B', $default_font_size - 2);
                $pdf->MultiCell(90, 3, $outputlangs->transnoentities("ErrorNoPaiementModeConfigured"), 0, 'L', 0);
                $pdf->SetTextColor(0, 0, 0);

                $posy = $pdf->GetY() + 1;
            }

            // Show payment mode
            if (isset($object->mode_reglement_code) && !empty($object->mode_reglement_code)
                    && $object->mode_reglement_code != 'CHQ'
                    && $object->mode_reglement_code != 'VIR') {
                $pdf->SetFont('', 'B', $default_font_size - 2);
                $pdf->SetXY($this->marge_gauche, $posy);
                $titre = $outputlangs->transnoentities("PaymentMode") . ':';
                $pdf->MultiCell(80, 5, $titre, 0, 'L');
                $pdf->SetFont('', '', $default_font_size - 2);
                $pdf->SetXY(50, $posy);
                $lib_mode_reg = $outputlangs->transnoentities("PaymentType" . $object->mode_reglement_code) != ('PaymentType' . $object->mode_reglement_code) ? $outputlangs->transnoentities("PaymentType" . $object->mode_reglement_code) : $outputlangs->convToOutputCharset($object->mode_reglement_code);
                $pdf->MultiCell(80, 5, $lib_mode_reg, 0, 'L');

                $posy = $pdf->GetY() + 2;
            }

            // Show payment mode CHQ
            if (empty($object->mode_reglement_code) || $object->mode_reglement_code == 'CHQ') {
                // Si mode reglement non force ou si force a CHQ
                if (!empty($conf->global->FACTURE_CHQ_NUMBER)) {
                    if ($conf->global->FACTURE_CHQ_NUMBER > 0) {
                        $account = new Account($this->db);
                        $account->fetch($conf->global->FACTURE_CHQ_NUMBER);

                        $pdf->SetXY($this->marge_gauche, $posy);
                        $pdf->SetFont('', 'B', $default_font_size - 3);
                        $pdf->MultiCell(100, 3, $outputlangs->transnoentities('PaymentByChequeOrderedTo', $account->proprio) . ':', 0, 'L', 0);
                        $posy = $pdf->GetY() + 1;

                        $pdf->SetXY($this->marge_gauche, $posy);
                        $pdf->SetFont('', '', $default_font_size - 3);
                        $pdf->MultiCell(100, 3, $outputlangs->convToOutputCharset($account->adresse_proprio), 0, 'L', 0);
                        $posy = $pdf->GetY() + 2;
                    }
                    if ($conf->global->FACTURE_CHQ_NUMBER == -1) {
                        $pdf->SetXY($this->marge_gauche, $posy);
                        $pdf->SetFont('', 'B', $default_font_size - 3);
                        $pdf->MultiCell(100, 3, $outputlangs->transnoentities('PaymentByChequeOrderedToShort') . ' ' . $outputlangs->convToOutputCharset($this->emetteur->name) . ' ' . $outputlangs->transnoentities('SendTo') . ':', 0, 'L', 0);
                        $posy = $pdf->GetY() + 1;

                        $pdf->SetXY($this->marge_gauche, $posy);
                        $pdf->SetFont('', '', $default_font_size - 3);
                        $pdf->MultiCell(100, 3, $outputlangs->convToOutputCharset($this->emetteur->getFullAddress()), 0, 'L', 0);
                        $posy = $pdf->GetY() + 2;
                    }
                }
            }

            // If payment mode not forced or forced to VIR, show payment with BAN
            if (empty($object->mode_reglement_code) || $object->mode_reglement_code == 'VIR') {
                if (!empty($conf->global->FACTURE_RIB_NUMBER)) {
                    $account = new Account($this->db);
                    $account->fetch($conf->global->FACTURE_RIB_NUMBER);

                    $curx = $this->marge_gauche;
                    $cury = $posy;
                    
                    $posy = pdf_bank($pdf, $outputlangs, $curx, $cury, $account, 0, $default_font_size);

                    $posy+=2;
                }
            }
        }

        return $posy;
    }

    /**
     * 	Show total to pay
     *
     * 	@param	PDF			&$pdf           Object PDF
     * 	@param  Facture		$object         Object invoice
     * 	@param  int			$deja_regle     Montant deja regle
     * 	@param	int			$posy			Position depart
     * 	@param	Translate	$outputlangs	Objet langs
     * 	@return int							Position pour suite
     */
    function _tableau_tot(&$pdf, $object, $deja_regle, $posy, $outputlangs) {
        global $conf, $mysoc;
        $default_font_size = pdf_getPDFFontSize($outputlangs);

        $tab2_top = $posy;
        $tab2_hl = 4;
        $pdf->SetFont('', '', $default_font_size - 1);

        // Tableau total
        $col1x = 120;
        $col2x = 170;
        $largcol2 = ($this->page_largeur - $this->marge_droite - $col2x);

        $index = 0;

        // Total HT
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetXY($col1x, $tab2_top + 0);
        $pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalHT"), 0, 'L', 1);

        $pdf->SetXY($col2x, $tab2_top + 0);
        $pdf->MultiCell($largcol2, $tab2_hl, price($object->total_ht + $object->remise), 0, 'R', 1);

        // Show VAT by rates and total
        $pdf->SetFillColor(248, 248, 248);

        $this->atleastoneratenotnull = 0;
        if (empty($conf->global->MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT)) {
            $tvaisnull = ((!empty($this->tva) && count($this->tva) == 1 && isset($this->tva['0.000']) && is_float($this->tva['0.000'])) ? true : false);
            if (!empty($conf->global->MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT_ISNULL) && $tvaisnull) {
                // Nothing to do
            } else {
                foreach ($this->tva as $tvakey => $tvaval) {
                    if ($tvakey > 0) {    // On affiche pas taux 0
                        $this->atleastoneratenotnull++;

                        $index++;
                        $pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

                        $tvacompl = '';
                        if (preg_match('/\*/', $tvakey)) {
                            $tvakey = str_replace('*', '', $tvakey);
                            $tvacompl = " (" . $outputlangs->transnoentities("NonPercuRecuperable") . ")";
                        }
                        $totalvat = $outputlangs->transnoentities("TotalVAT") . ' ';
                        $totalvat.=vatrate($tvakey, 1) . $tvacompl;
                        $pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', 1);

                        $pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
                        $pdf->MultiCell($largcol2, $tab2_hl, price($tvaval), 0, 'R', 1);
                    }
                }

                if (!$this->atleastoneratenotnull) { // If no vat at all
                    $index++;
                    $pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
                    $pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalVAT"), 0, 'L', 1);

                    $pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
                    $pdf->MultiCell($largcol2, $tab2_hl, price($object->total_tva), 0, 'R', 1);

                    // Total LocalTax1
                    if (!empty($conf->global->FACTURE_LOCAL_TAX1_OPTION) && $conf->global->FACTURE_LOCAL_TAX1_OPTION == 'localtax1on' && $object->total_localtax1 > 0) {
                        $index++;
                        $pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
                        $pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalLT1" . $mysoc->pays_code), 0, 'L', 1);
                        $pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
                        $pdf->MultiCell($largcol2, $tab2_hl, price($object->total_localtax1), 0, 'R', 1);
                    }

                    // Total LocalTax2
                    if (!empty($conf->global->FACTURE_LOCAL_TAX2_OPTION) && $conf->global->FACTURE_LOCAL_TAX2_OPTION == 'localtax2on' && $object->total_localtax2 > 0) {
                        $index++;
                        $pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
                        $pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalLT2" . $mysoc->pays_code), 0, 'L', 1);
                        $pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
                        $pdf->MultiCell($largcol2, $tab2_hl, price($object->total_localtax2), 0, 'R', 1);
                    }
                } else {
                    if (!empty($conf->global->FACTURE_LOCAL_TAX1_OPTION) && $conf->global->FACTURE_LOCAL_TAX1_OPTION == 'localtax1on') {
                        //Local tax 1
                        foreach ($this->localtax1 as $tvakey => $tvaval) {
                            if ($tvakey != 0) {    // On affiche pas taux 0
                                //$this->atleastoneratenotnull++;

                                $index++;
                                $pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

                                $tvacompl = '';
                                if (preg_match('/\*/', $tvakey)) {
                                    $tvakey = str_replace('*', '', $tvakey);
                                    $tvacompl = " (" . $outputlangs->transnoentities("NonPercuRecuperable") . ")";
                                }
                                $totalvat = $outputlangs->transnoentities("TotalLT1" . $mysoc->pays_code) . ' ';
                                $totalvat.=vatrate($tvakey, 1) . $tvacompl;
                                $pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', 1);

                                $pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
                                $pdf->MultiCell($largcol2, $tab2_hl, price($tvaval), 0, 'R', 1);
                            }
                        }
                    }

                    if (!empty($conf->global->FACTURE_LOCAL_TAX2_OPTION) && $conf->global->FACTURE_LOCAL_TAX2_OPTION == 'localtax2on') {
                        //Local tax 2
                        foreach ($this->localtax2 as $tvakey => $tvaval) {
                            if ($tvakey != 0) {    // On affiche pas taux 0
                                //$this->atleastoneratenotnull++;

                                $index++;
                                $pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

                                $tvacompl = '';
                                if (preg_match('/\*/', $tvakey)) {
                                    $tvakey = str_replace('*', '', $tvakey);
                                    $tvacompl = " (" . $outputlangs->transnoentities("NonPercuRecuperable") . ")";
                                }
                                $totalvat = $outputlangs->transnoentities("TotalLT2" . $mysoc->pays_code) . ' ';
                                $totalvat.=vatrate($tvakey, 1) . $tvacompl;
                                $pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', 1);

                                $pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
                                $pdf->MultiCell($largcol2, $tab2_hl, price($tvaval), 0, 'R', 1);
                            }
                        }
                    }
                }

                $useborder = 0;

                // Total TTC
                $index++;
                $pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
                $pdf->SetTextColor(0, 0, 60);
                $pdf->SetFillColor(224, 224, 224);
                $pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalTTC"), $useborder, 'L', 1);

                $pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
                $pdf->MultiCell($largcol2, $tab2_hl, price($object->total_ttc), $useborder, 'R', 1);
            }
        }

        $pdf->SetTextColor(0, 0, 0);

        /*
          $resteapayer = $object->total_ttc - $deja_regle;
          if (! empty($object->paye)) $resteapayer=0;
         */

        if ($deja_regle > 0) {
            $index++;

            $pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
            $pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("AlreadyPaid"), 0, 'L', 0);

            $pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
            $pdf->MultiCell($largcol2, $tab2_hl, price($deja_regle), 0, 'R', 0);

            /*
              if ($object->close_code == 'discount_vat')
              {
              $index++;
              $pdf->SetFillColor(255,255,255);

              $pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
              $pdf->MultiCell($col2x-$col1x, $tab2_hl, $outputlangs->transnoentities("EscompteOffered"), $useborder, 'L', 1);

              $pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
              $pdf->MultiCell($largcol2, $tab2_hl, price($object->total_ttc - $deja_regle), $useborder, 'R', 1);

              $resteapayer=0;
              }
             */

            $index++;
            $pdf->SetTextColor(0, 0, 60);
            $pdf->SetFillColor(224, 224, 224);
            $pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
            $pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("RemainderToPay"), $useborder, 'L', 1);

            $pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
            $pdf->MultiCell($largcol2, $tab2_hl, price($resteapayer), $useborder, 'R', 1);

            // Fin
            $pdf->SetFont('', '', $default_font_size - 1);
            $pdf->SetTextColor(0, 0, 0);
        }

        $index++;
        return ($tab2_top + ($tab2_hl * $index));
    }

    /**
     *   Show table for lines
     *
     *   @param		PDF			&$pdf     		Object PDF
     *   @param		string		$tab_top		Top position of table
     *   @param		string		$tab_height		Height of table (rectangle)
     *   @param		int			$nexY			Y
     *   @param		Translate	$outputlangs	Langs object
     *   @param		int			$hidetop		Hide top bar of array
     *   @param		int			$hidebottom		Hide bottom bar of array
     *   @return	void
     */
    function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0) {
        global $conf;

        // Force to disable hidetop and hidebottom
        $hidebottom = 0;
        if ($hidetop)
            $hidetop = -1;

        $default_font_size = pdf_getPDFFontSize($outputlangs);

        // Amount in (at tab_top - 1)
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('', '', $default_font_size - 2);

        if (empty($hidetop)) {
            $titre = $outputlangs->transnoentities("AmountInCurrency", $outputlangs->transnoentitiesnoconv("Currency" . $conf->currency));
            $pdf->SetXY($this->page_largeur - $this->marge_droite - ($pdf->GetStringWidth($titre) + 3), $tab_top - 4);
            $pdf->MultiCell(($pdf->GetStringWidth($titre) + 3), 2, $titre);
        }

        $pdf->SetDrawColor(128, 128, 128);
        $pdf->SetFont('', '', $default_font_size - 1);

        // Output Rect
        $this->printRect($pdf, $this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $tab_height, $hidetop, $hidebottom); // Rect prend une longueur en 3eme param et 4eme param

        if (empty($hidetop)) {
            $pdf->line($this->marge_gauche, $tab_top + 5, $this->page_largeur - $this->marge_droite, $tab_top + 5); // line prend une position y en 2eme param et 4eme param

            $pdf->SetXY($this->posxdesc - 1, $tab_top + 1);
            $pdf->MultiCell(108, 2, $outputlangs->transnoentities("Designation"), '', 'L');
        }

        if (empty($conf->global->MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT)) {
            $pdf->line($this->posxtva - 1, $tab_top, $this->posxtva - 1, $tab_top + $tab_height);
            if (empty($hidetop)) {
                $pdf->SetXY($this->posxtva - 3, $tab_top + 1);
                $pdf->MultiCell($this->posxup - $this->posxtva + 3, 2, $outputlangs->transnoentities("VAT"), '', 'C');
            }
        }

        $pdf->line($this->posxup - 1, $tab_top, $this->posxup - 1, $tab_top + $tab_height);
        if (empty($hidetop)) {
            $pdf->SetXY($this->posxup - 1, $tab_top + 1);
            $pdf->MultiCell($this->posxqty - $this->posxup - 1, 2, $outputlangs->transnoentities("PriceUHT"), '', 'C');
        }

        $pdf->line($this->posxqty - 1, $tab_top, $this->posxqty - 1, $tab_top + $tab_height);
        if (empty($hidetop)) {
            $pdf->SetXY($this->posxqty - 1, $tab_top + 1);
            $pdf->MultiCell($this->posxdiscount - $this->posxqty - 1, 2, $outputlangs->transnoentities("Qty"), '', 'C');
        }

        $pdf->line($this->posxdiscount - 1, $tab_top, $this->posxdiscount - 1, $tab_top + $tab_height);
        if (empty($hidetop)) {
            if ($this->atleastonediscount) {
                $pdf->SetXY($this->posxdiscount - 1, $tab_top + 1);
                $pdf->MultiCell($this->postotalht - $this->posxdiscount + 1, 2, $outputlangs->transnoentities("ReductionShort"), '', 'C');
            }
        }
        if ($this->atleastonediscount) {
            $pdf->line($this->postotalht, $tab_top, $this->postotalht, $tab_top + $tab_height);
        }
        if (empty($hidetop)) {
            $pdf->SetXY($this->postotalht - 1, $tab_top + 1);
            $pdf->MultiCell(30, 2, $outputlangs->transnoentities("TotalHT"), '', 'C');
        }
    }

    /**
     *  Show top header of page.
     *
     *  @param	PDF			&$pdf     		Object PDF
     *  @param  Object		$object     	Object to show
     *  @param  int	    	$showaddress    0=no, 1=yes
     *  @param  Translate	$outputlangs	Object lang for output
     *  @param	object		$hookmanager	Hookmanager object
     *  @return	void
     */
    function _pagehead(&$pdf, $object, $showaddress, $outputlangs, $hookmanager) {
        global $conf, $langs;

        $outputlangs->load("main");
        $outputlangs->load("bills");
        $outputlangs->load("propal");
        $outputlangs->load("companies");

        $default_font_size = pdf_getPDFFontSize($outputlangs);

        pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

        //  Show Draft Watermark
        if ($object->statut == 0 && (!empty($conf->global->PROPALE_DRAFT_WATERMARK))) {
            pdf_watermark($pdf, $outputlangs, $this->page_hauteur, $this->page_largeur, 'mm', $conf->global->PROPALE_DRAFT_WATERMARK);
        }

        $pdf->SetTextColor(0, 0, 60);
        $pdf->SetFont('', 'B', $default_font_size + 3);

        $posy = $this->marge_haute;
        $posx = $this->page_largeur - $this->marge_droite - 100;

        $pdf->SetXY($this->marge_gauche, $posy);

        // Logo
        $logo = $conf->mycompany->dir_output . '/logos/' . $this->emetteur->logo;
        if ($this->emetteur->logo) {
            if (is_readable($logo)) {
                $height = pdf_getHeightForLogo($logo);
                $pdf->Image($logo, $this->marge_gauche, $posy, 0, $height); // width=0 (auto)
            } else {
                $pdf->SetTextColor(200, 0, 0);
                $pdf->SetFont('', 'B', $default_font_size - 2);
                $pdf->MultiCell(100, 3, $outputlangs->transnoentities("ErrorLogoFileNotFound", $logo), 0, 'L');
                $pdf->MultiCell(100, 3, $outputlangs->transnoentities("ErrorGoToGlobalSetup"), 0, 'L');
            }
        } else {
            $text = $this->emetteur->name;
            $pdf->MultiCell(100, 4, $outputlangs->convToOutputCharset($text), 0, 'L');
        }

        $pdf->SetFont('', 'B', $default_font_size + 3);
        $pdf->SetXY($posx, $posy);
        $pdf->SetTextColor(0, 0, 60);
        $title = $outputlangs->transnoentities("CommercialProposal");
        $pdf->MultiCell(100, 4, $title, '', 'R');

        $pdf->SetFont('', 'B', $default_font_size);

        $posy+=5;
        $pdf->SetXY($posx, $posy);
        $pdf->SetTextColor(0, 0, 60);
        $pdf->MultiCell(100, 4, $outputlangs->transnoentities("Ref") . " : " . $outputlangs->convToOutputCharset($object->ref), '', 'R');

        $posy+=1;
        $pdf->SetFont('', '', $default_font_size - 1);

        if ($object->ref_client) {
            $posy+=5;
            $pdf->SetXY($posx, $posy);
            $pdf->SetTextColor(0, 0, 60);
            $pdf->MultiCell(100, 3, $outputlangs->transnoentities("RefCustomer") . " : " . $outputlangs->convToOutputCharset($object->ref_client), '', 'R');
        }

        $posy+=4;
        $pdf->SetXY($posx, $posy);
        $pdf->SetTextColor(0, 0, 60);
        $pdf->MultiCell(100, 3, $outputlangs->transnoentities("Date") . " : " . dol_print_date($object->date, "day", false, $outputlangs, true), '', 'R');

        $posy+=4;
        $pdf->SetXY($posx, $posy);
        $pdf->SetTextColor(0, 0, 60);
        $pdf->MultiCell(100, 3, $outputlangs->transnoentities("DateEndPropal") . " : " . dol_print_date($object->fin_validite, "day", false, $outputlangs, true), '', 'R');

        if ($object->thirdparty->code_client) {
            $posy+=4;
            $pdf->SetXY($posx, $posy);
            $pdf->SetTextColor(0, 0, 60);
            $pdf->MultiCell(100, 3, $outputlangs->transnoentities("CustomerCode") . " : " . $outputlangs->transnoentities($object->thirdparty->code_client), '', 'R');
        }

        $posy+=2;

        // Show list of linked objects
        $posy = pdf_writeLinkedObjects($pdf, $object, $outputlangs, $posx, $posy, 100, 3, 'R', $default_font_size, $hookmanager);

        if ($showaddress) {
            // Sender properties
            $carac_emetteur = '';
            // Add internal contact of proposal if defined
            $arrayidcontact = $object->getIdContact('internal', 'SALESREPFOLL');
            if (count($arrayidcontact) > 0) {
                $object->fetch_user($arrayidcontact[0]);
                $carac_emetteur .= ($carac_emetteur ? "\n" : '' ) . $outputlangs->transnoentities("Name") . ": " . $outputlangs->convToOutputCharset($object->user->getFullName($outputlangs)) . "\n";
            }

            $carac_emetteur .= pdf_build_address($outputlangs, $this->emetteur);

            // Show sender
            $posy = 42;
            $posx = $this->marge_gauche;
            if (!empty($conf->global->MAIN_INVERT_SENDER_RECIPIENT))
                $posx = $this->page_largeur - $this->marge_droite - 80;
            $hautcadre = 40;

            // Show sender frame
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('', '', $default_font_size - 2);
            $pdf->SetXY($posx, $posy - 5);
            $pdf->MultiCell(66, 5, $outputlangs->transnoentities("BillFrom") . ":", 0, 'L');
            $pdf->SetXY($posx, $posy);
            $pdf->SetFillColor(230, 230, 230);
            $pdf->MultiCell(82, $hautcadre, "", 0, 'R', 1);
            $pdf->SetTextColor(0, 0, 60);

            // Show sender name
            $pdf->SetXY($posx + 2, $posy + 3);
            $pdf->SetFont('', 'B', $default_font_size);
            $pdf->MultiCell(80, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');

            // Show sender information
            $pdf->SetXY($posx + 2, $posy + 8);
            $pdf->SetFont('', '', $default_font_size - 1);
            $pdf->MultiCell(80, 4, $carac_emetteur, 0, 'L');


            // If CUSTOMER contact defined, we use it
            $usecontact = false;
            $arrayidcontact = $object->getIdContact('external', 'CUSTOMER');
            if (count($arrayidcontact) > 0) {
                $usecontact = true;
                $result = $object->fetch_contact($arrayidcontact[0]);
            }

            // Recipient name
            if (!empty($usecontact)) {
                // On peut utiliser le nom de la societe du contact
                if (!empty($conf->global->MAIN_USE_COMPANY_NAME_OF_CONTACT))
                    $socname = $object->contact->socname;
                else
                    $socname = $object->thirdparty->name;
                $carac_client_name = $outputlangs->convToOutputCharset($socname);
            }
            else {
                $carac_client_name = $outputlangs->convToOutputCharset($object->thirdparty->name);
            }

            $carac_client = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, ($usecontact ? $object->contact : ''), $usecontact, 'target');

            // Show recipient
            $posy = 42;
            $posx = $this->page_largeur - $this->marge_droite - 100;
            if (!empty($conf->global->MAIN_INVERT_SENDER_RECIPIENT))
                $posx = $this->marge_gauche;

            // Show recipient frame
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('', '', $default_font_size - 2);
            $pdf->SetXY($posx + 2, $posy - 5);
            $pdf->MultiCell(80, 5, $outputlangs->transnoentities("BillTo") . ":", 0, 'L');
            $pdf->Rect($posx, $posy, 100, $hautcadre);

            // Show recipient name
            $pdf->SetXY($posx + 2, $posy + 3);
            $pdf->SetFont('', 'B', $default_font_size);
            $pdf->MultiCell(96, 4, $carac_client_name, 0, 'L');

            // Show recipient information
            $pdf->SetFont('', '', $default_font_size - 1);
            $pdf->SetXY($posx + 2, $posy + 4 + (dol_nboflines_bis($carac_client_name, 50) * 4));
            $pdf->MultiCell(86, 4, $carac_client, 0, 'L');
        }
    }

    /**
     *   	Show footer of page. Need this->emetteur object
     *
     *   	@param	PDF			&$pdf     			PDF
     * 		@param	Object		$object				Object to show
     *      @param	Translate	$outputlangs		Object lang for output
     *      @return	int								Return height of bottom margin including footer text
     */
    function _pagefoot(&$pdf, $object, $outputlangs) {
        return pdf_pagefoot($pdf, $outputlangs, 'PROPALE_FREE_TEXT', $this->emetteur, $this->marge_basse, $this->marge_gauche, $this->page_hauteur, $object);
    }

}

?>