<?php
/* Copyright (C) 2004-2011 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2011 Regis Houssin        <regis@dolibarr.fr>
 * Copyright (C) 2008      Raphael Bertrand     <raphael.bertrand@resultic.fr>
 * Copyright (C) 2010-2011 Juanjo Menent	    <jmenent@2byte.es>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 * or see http://www.gnu.org/
 */

/**
 *	\file       htdocs/includes/modules/propale/pdf_propale_azur.modules.php
 *	\ingroup    propale
 *	\brief      Fichier de la classe permettant de generer les propales au modele Azur
 *	\author	    Laurent Destailleur
 */
require_once(DOL_DOCUMENT_ROOT."/core/modules/propale/modules_propale.php");
require_once(DOL_DOCUMENT_ROOT."/product/class/product.class.php");
require_once(DOL_DOCUMENT_ROOT."/core/lib/company.lib.php");
require_once(DOL_DOCUMENT_ROOT."/core/lib/functions2.lib.php");
require_once(DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php');


/**
 *	\class      pdf_propale_azur
 *	\brief      Classe permettant de generer les propales au modele Azur
 */
class pdf_propale_BE extends ModelePDFPropales
{
	var $emetteur;	// Objet societe qui emet


	/**
	 *	Constructor
	 *
	 *  @param		DoliDB		$DB      Database handler
	 */
	function pdf_propale_BE($db)
	{
		global $conf,$langs,$mysoc;

		$langs->load("main");
		$langs->load("bills");

		$this->db = $db;
		$this->name = "BE";
		$this->description = $langs->trans('DocModelBEDescription');

		// Dimension page pour format A4
		$this->type = 'pdf';
		$formatarray=pdf_getFormat();
		$this->page_largeur = $formatarray['width'];
		$this->page_hauteur = $formatarray['height'];
		$this->format = array($this->page_largeur,$this->page_hauteur);
		$this->marge_gauche=10;
		$this->marge_droite=10;
		$this->marge_haute=10;
		$this->marge_basse=10;

		$this->option_logo = 1;                    // Affiche logo
		$this->option_tva = 1;                     // Gere option tva FACTURE_TVAOPTION
		$this->option_modereg = 1;                 // Affiche mode reglement
		$this->option_condreg = 1;                 // Affiche conditions reglement
		$this->option_codeproduitservice = 1;      // Affiche code produit-service
		$this->option_multilang = 1;               // Dispo en plusieurs langues
		$this->option_escompte = 1;                // Affiche si il y a eu escompte
		$this->option_credit_note = 1;             // Gere les avoirs
		$this->option_freetext = 1;				   // Support add of a personalised text
		$this->option_draft_watermark = 1;		   //Support add of a watermark on drafts

		$this->franchise=!$mysoc->tva_assuj;

		// Recupere emetteur
		$this->emetteur=$mysoc;
		if (! $this->emetteur->pays_code) $this->emetteur->pays_code=substr($langs->defaultlang,-2);    // Par defaut, si n'etait pas defini

		// Defini position des colonnes
		$this->posxdesc=$this->marge_gauche+1;
		$this->posxtva=111;
		$this->posxup=126;
		$this->posxqty=174;
		$this->posxdiscount=162;
		$this->postotalht=174;

		$this->tva=array();
		$this->localtax1=array();
		$this->localtax2=array();
		$this->atleastoneratenotnull=0;
		$this->atleastonediscount=0;
	}

	/**
     *  Function to build pdf onto disk
     *  
     *  @param		int		$object				Id of object to generate
     *  @param		object	$outputlangs		Lang output object
     *  @param		string	$srctemplatepath	Full path of source filename for generator using a template file
     *  @param		int		$hidedetails		Do not show line details
     *  @param		int		$hidedesc			Do not show desc
     *  @param		int		$hideref			Do not show ref
     *  @param		object	$hookmanager		Hookmanager object
     *  @return     int             			1=OK, 0=KO
	 */
	function write_file($object,$outputlangs,$srctemplatepath='',$hidedetails=0,$hidedesc=0,$hideref=0,$hookmanager=false)
	{
		global $user,$langs,$conf;
		$default_font_size = pdf_getPDFFontSize($outputlangs);

		if (! is_object($outputlangs)) $outputlangs=$langs;
		// For backward compatibility with FPDF, force output charset to ISO, because FPDF expect text to be encoded in ISO
		$sav_charset_output=$outputlangs->charset_output;
		if (! empty($conf->global->MAIN_USE_FPDF)) $outputlangs->charset_output='ISO-8859-1';

		$outputlangs->load("main");
		$outputlangs->load("dict");
		$outputlangs->load("companies");
		$outputlangs->load("bills");
		$outputlangs->load("propal");
		$outputlangs->load("products");

		if ($conf->propal->dir_output)
		{
			$object->fetch_thirdparty();

			$deja_regle = "";

			// Definition de $dir et $file
			if ($object->specimen)
			{
				$dir = $conf->propal->dir_output;
				$file = $dir . "/SPECIMEN.pdf";
			}
			else
			{
				$objectref = dol_sanitizeFileName($object->ref);
				$dir = $conf->propal->dir_output . "/" . $objectref;
				$file = $dir . "/" . $objectref . ".pdf";
			}

			if (! file_exists($dir))
			{
				if (dol_mkdir($dir) < 0)
				{
					$this->error=$langs->trans("ErrorCanNotCreateDir",$dir);
					return 0;
				}
			}

			if (file_exists($dir))
			{
				$nblignes = count($object->lines);

				// Create pdf instance
                $pdf=pdf_getInstance($this->format);

                if (class_exists('TCPDF'))
                {
                    $pdf->setPrintHeader(false);
                    $pdf->setPrintFooter(false);
                }
                $pdf->SetFont(pdf_getPDFFont($outputlangs));

				$pdf->Open();
				$pagenb=0;
				$pdf->SetDrawColor(128,128,128);

				$pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
				$pdf->SetSubject($outputlangs->transnoentities("CommercialProposal"));
				$pdf->SetCreator("Dolibarr ".DOL_VERSION);
				$pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
				$pdf->SetKeyWords($outputlangs->convToOutputCharset($object->ref)." ".$outputlangs->transnoentities("CommercialProposal"));
				if ($conf->global->MAIN_DISABLE_PDF_COMPRESSION) $pdf->SetCompression(false);

				$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);   // Left, Top, Right
				$pdf->SetAutoPageBreak(1,0);

				// Positionne $this->atleastonediscount si on a au moins une remise
				for ($i = 0 ; $i < $nblignes ; $i++)
				{
					if ($object->lines[$i]->remise_percent)
					{
						$this->atleastonediscount++;
					}
				}

				// New page
				$pdf->AddPage();
				$pagenb++;
				$this->_pagehead($pdf, $object, 1, $outputlangs);
				$pdf->SetFont('','', $default_font_size - 1);
				$pdf->MultiCell(0, 4, '');		// Set interline to 4
				$pdf->SetTextColor(0,0,0);

				$tab_top = 90;
				$tab_top_middlepage = 50;
				$tab_top_newpage = 50;
				$tab_height = 130;
				$tab_height_middlepage = 200;
				$tab_height_endpage = 170;

				// Affiche notes
				if (! empty($object->note_public))
				{
					$tab_top = 88;

					$pdf->SetFont('','', $default_font_size - 1);   // Dans boucle pour gerer multi-page
					//$pdf->SetXY($this->posxdesc-1, $tab_top);
					//$pdf->MultiCell(190, 4, $outputlangs->convToOutputCharset($object->note_public), 0, 'L');
					$pdf->writeHTMLCell(190, 4, $this->posxdesc-1, $tab_top, $outputlangs->convToOutputCharset($object->note_public), 0, 1);
					$nexY = $pdf->GetY();
					$height_note=$nexY-$tab_top;

					// Rect prend une longueur en 3eme param
					$pdf->SetDrawColor(192,192,192);
					$pdf->Rect($this->marge_gauche, $tab_top-1, $this->page_largeur-$this->marge_gauche-$this->marge_droite, $height_note+1);

					$tab_height = $tab_height - $height_note;
					$tab_top = $nexY+6;
				}
				else
				{
					$height_note=0;
				}

				$iniY = $tab_top + 7;
				$curY = $tab_top + 7;
				$nexY = $tab_top + 7;

				// Loop on each lines
				for ($i = 0 ; $i < $nblignes ; $i++)
				{
					$curY = $nexY;

					$pdf->SetFont('','', $default_font_size - 1);   // Dans boucle pour gerer multi-page

					// Description de la ligne produit
					$curX = $this->posxdesc-1;
					pdf_writelinedesc($pdf,$object,$i,$outputlangs,$this->posxtva-$curX,4,$curX,$curY,$hideref,$hidedesc,0,$hookmanager);

					$pdf->SetFont('','', $default_font_size - 1);   // On repositionne la police par defaut
					$nexY = $pdf->GetY();

					// TVA
					/*
					if (empty($conf->global->MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT))
					{
						$vat_rate = pdf_getlinevatrate($object, $i, $outputlangs, $hidedetails, $hookmanager);
						$pdf->SetXY($this->posxtva, $curY);
						$pdf->MultiCell($this->posxup-$this->posxtva-1, 4, $vat_rate, 0, 'R');
					}

					// Prix unitaire HT avant remise
					$up_excl_tax = pdf_getlineupexcltax($object, $i, $outputlangs, $hidedetails, $hookmanager);
					$pdf->SetXY($this->posxup, $curY);
					$pdf->MultiCell($this->posxqty-$this->posxup-1, 4, $up_excl_tax, 0, 'R', 0);
					*/
					// Quantity
					$qty = pdf_getlineqty($object, $i, $outputlangs, $hidedetails, $hookmanager);
					$pdf->SetXY($this->posxqty, $curY);
					$pdf->MultiCell($this->posxdiscount-$this->posxqty-1, 4, $qty, 0, 'R');

					// Remise sur ligne
					/*
					$pdf->SetXY($this->posxdiscount, $curY);
					if ($object->lines[$i]->remise_percent)
					{
						$remise_percent = pdf_getlineremisepercent($object, $i, $outputlangs, $hidedetails, $hookmanager);
						$pdf->MultiCell($this->postotalht-$this->posxdiscount-1, 4, $remise_percent, 0, 'R');
					}

					// Total HT ligne
					$total_excl_tax = pdf_getlinetotalexcltax($object, $i, $outputlangs, $hidedetails, $hookmanager);
					$pdf->SetXY($this->postotalht, $curY);
					$pdf->MultiCell(26, 4, $total_excl_tax, 0, 'R', 0);

					// Collecte des totaux par valeur de tva dans $this->tva["taux"]=total_tva
					$tvaligne=$object->lines[$i]->total_tva;
					$localtax1ligne=$object->lines[$i]->total_localtax1;
					$localtax2ligne=$object->lines[$i]->total_localtax2;

					if ($object->remise_percent) $tvaligne-=($tvaligne*$object->remise_percent)/100;
					if ($object->remise_percent) $localtax1ligne-=($localtax1ligne*$object->remise_percent)/100;
					if ($object->remise_percent) $localtax2ligne-=($localtax2ligne*$object->remise_percent)/100;

					$vatrate=(string) $object->lines[$i]->tva_tx;
					$localtax1rate=(string) $object->lines[$i]->localtax1_tx;
					$localtax2rate=(string) $object->lines[$i]->localtax2_tx;

					if (($object->lines[$i]->info_bits & 0x01) == 0x01) $vatrate.='*';

					$this->tva[$vatrate] += $tvaligne;
					$this->localtax1[$localtax1rate]+=$localtax1ligne;
					$this->localtax2[$localtax2rate]+=$localtax2ligne;
					*/
					$nexY+=2;    // Passe espace entre les lignes

					// Cherche nombre de lignes a venir pour savoir si place suffisante
					if ($i < ($nblignes - 1) && empty($hidedesc))	// If it's not last line
					{
						//on recupere la description du produit suivant
						$follow_descproduitservice = $object->lines[$i+1]->desc;
						//on compte le nombre de ligne afin de verifier la place disponible (largeur de ligne 52 caracteres)
						$nblineFollowDesc = (dol_nboflines_bis($follow_descproduitservice,52,$outputlangs->charset_output)*4);
					}
					else	// If it's last line
					{
						$nblineFollowDesc = 0;
					}

					// Test if a new page is required
					if ($pagenb == 1)
					{
						$tab_top_in_current_page=$tab_top;
						$tab_height_in_current_page=$tab_height;
					}
					else
					{
						$tab_top_in_current_page=$tab_top_newpage;
						$tab_height_in_current_page=$tab_height_middlepage;
					}
					if (($nexY+$nblineFollowDesc) > ($tab_top_in_current_page+$tab_height_in_current_page) && $i < ($nblignes - 1))
					{
						if ($pagenb == 1)
						{
							$this->_tableau($pdf, $tab_top, $tab_height + 20, $nexY, $outputlangs);
						}
						else
						{
							$this->_tableau($pdf, $tab_top_newpage, $tab_height_middlepage, $nexY, $outputlangs);
						}

						$this->_pagefoot($pdf,$object,$outputlangs);

						// New page
						$pdf->AddPage();
						$pagenb++;
						$this->_pagehead($pdf, $object, 0, $outputlangs);
						$pdf->SetFont('','', $default_font_size - 1);
						$pdf->MultiCell(0, 3, '');		// Set interline to 3
						$pdf->SetTextColor(0,0,0);

						$nexY = $tab_top_newpage + 7;
					}

				}

				// Show square
				if ($pagenb == 1)
				{
					$this->_tableau($pdf, $tab_top, $tab_height, $nexY, $outputlangs);
					$bottomlasttab=$tab_top + $tab_height + 1;
				}
				else
				{
					$this->_tableau($pdf, $tab_top_newpage, $tab_height_endpage, $nexY, $outputlangs);
					$bottomlasttab=$tab_top_newpage + $tab_height_endpage + 1;
				}

				

				// Pied de page
				$this->_pagefoot($pdf,$object,$outputlangs);
				if (method_exists($pdf,'AliasNbPages')) $pdf->AliasNbPages();

				$pdf->Close();

				$pdf->Output($file,'F');
				if (! empty($conf->global->MAIN_UMASK))
				@chmod($file, octdec($conf->global->MAIN_UMASK));

				// Add external file
				//$pdfConcat = new concat_pdf();
				//$pdfConcat->setFiles(array($file, DOL_DOCUMENT_ROOT."/core/modules/propale/morefile.pdf"));
				//$pdfConcat->concat();
				//if (method_exists($pdf,'AliasNbPages')) $pdf->AliasNbPages();
				//$pdfConcat->Output($file,'F');

				$outputlangs->charset_output=$sav_charset_output;
				return 1;   // Pas d'erreur
			}
			else
			{
				$this->error=$langs->trans("ErrorCanNotCreateDir",$dir);
				return 0;
			}
		}
		else
		{
			$this->error=$langs->trans("ErrorConstantNotDefined","PROP_OUTPUTDIR");
			return 0;
		}

		$this->error=$langs->trans("ErrorUnknown");
		return 0;   // Erreur par defaut
	}

	/*
	 *   Affiche tableau des versement
	 *
	 *   @param      pdf     	Objet PDF
	 *   @param      object		Objet propale
	 */
	function _tableau_versements(&$pdf, $object, $posy, $outputlangs)
	{

	}


	
	/**
	 *   Affiche la grille des lignes de propales
	 *
	 *   @param      pdf     objet PDF
	 */
	function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs)
	{
		global $conf;
		$default_font_size = pdf_getPDFFontSize($outputlangs);

		$pdf->SetDrawColor(128,128,128);

		// Rect prend une longueur en 3eme param
		$pdf->Rect($this->marge_gauche, $tab_top, $this->page_largeur-$this->marge_gauche-$this->marge_droite, $tab_height);
		// line prend une position y en 3eme param
		$pdf->line($this->marge_gauche, $tab_top+5, $this->page_largeur-$this->marge_droite, $tab_top+5);

		$pdf->SetFont('','',$default_font_size - 1);

		$pdf->SetXY($this->posxdesc-1, $tab_top+1);
		$pdf->MultiCell(108,2, $outputlangs->transnoentities("Designation"),'','L');

		$pdf->line($this->posxqty-1, $tab_top, $this->posxqty-1, $tab_top + $tab_height);
		$pdf->SetXY($this->posxqty-1, $tab_top+1);
		$pdf->MultiCell($this->posxdiscount-$this->posxqty-1,2, $outputlangs->transnoentities("Qty"),'','C');

		
	}

	/**
	 *   	Show header of document
	 *
	 *   	@param      pdf     		Object PDF
	 *   	@param      object			Object commercial proposal
	 *      @param      showaddress     0=no, 1=yes
	 *      @param      outputlangs    	Object lang for output
	 */
	function _pagehead(&$pdf, $object, $showaddress=1, $outputlangs)
	{
		global $conf,$langs;
		$default_font_size = pdf_getPDFFontSize($outputlangs);

		$outputlangs->load("main");
		$outputlangs->load("bills");
		$outputlangs->load("propal");
		$outputlangs->load("companies");

		pdf_pagehead($pdf,$outputlangs,$this->page_hauteur);

		//Affiche le filigrane brouillon - Print Draft Watermark
		if($object->statut==0 && (! empty($conf->global->PROPALE_DRAFT_WATERMARK)) )
		{
            pdf_watermark($pdf,$outputlangs,$this->page_hauteur,$this->page_largeur,'mm',$conf->global->PROPALE_DRAFT_WATERMARK);
		}

		//Prepare la suite
		$pdf->SetTextColor(0,0,60);
		$pdf->SetFont('','B', $default_font_size + 3);

        $posx=$this->page_largeur-$this->marge_droite-100;
		$posy=$this->marge_haute;

		$pdf->SetXY($this->marge_gauche,$posy);

		// Logo
		$logo=$conf->mycompany->dir_output.'/logos/'.$this->emetteur->logo;
		if ($this->emetteur->logo)
		{
			if (is_readable($logo))
			{
				$pdf->Image($logo, $this->marge_gauche, 4, 0, 36);
			}
			else
			{
				$pdf->SetTextColor(200,0,0);
				$pdf->SetFont('','B',$default_font_size - 2);
				$pdf->MultiCell(100, 3, $outputlangs->transnoentities("ErrorLogoFileNotFound",$logo), 0, 'L');
				$pdf->MultiCell(100, 3, $outputlangs->transnoentities("ErrorGoToGlobalSetup"), 0, 'L');
			}
		}
		else
		{
			$text=$this->emetteur->name;
			$pdf->MultiCell(100, 4, $outputlangs->convToOutputCharset($text), 0, 'L');
		}

		$pdf->SetFont('','B',$default_font_size + 3);
		$pdf->SetXY($posx,$posy);
		$pdf->SetTextColor(0,0,60);
		$txt = "Bon de Prise en Charge";
		$txt = utf8_encode($txt);
		$pdf->MultiCell(100, 4, $txt.": " . $outputlangs->convToOutputCharset($object->ref), '' , 'R');

		$posy+=1;
		$pdf->SetFont('','', $default_font_size);

		if ($object->ref_client)
		{
			$posy+=5;
			$pdf->SetXY($posx,$posy);
			$pdf->SetTextColor(0,0,60);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("RefCustomer")." : " . $outputlangs->convToOutputCharset($object->ref_client), '', 'R');
		}

		$posy+=4;
		$pdf->SetXY($posx,$posy);
		$pdf->SetTextColor(0,0,60);
		$pdf->MultiCell(100, 3, $outputlangs->transnoentities("Date")." : " . dol_print_date($object->date,"day",false,$outputlangs,true), '', 'R');

		
		if ($object->thirdparty->code_client)
		{
			$posy+=4;
			$pdf->SetXY($posx,$posy);
			$pdf->SetTextColor(0,0,60);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("CustomerCode")." : " . $outputlangs->transnoentities($object->thirdparty->code_client), '', 'R');
		}

		if ($showaddress)
		{
			// Sender properties
			$carac_emetteur='';
		 	// Add internal contact of proposal if defined
			$arrayidcontact=$object->getIdContact('internal','SALESREPFOLL');
		 	if (count($arrayidcontact) > 0)
		 	{
		 		$object->fetch_user($arrayidcontact[0]);
		 		$carac_emetteur .= ($carac_emetteur ? "\n" : '' ).$outputlangs->transnoentities("Name").": ".$outputlangs->convToOutputCharset($object->user->getFullName($outputlangs))."\n";
		 	}

		 	$carac_emetteur .= pdf_build_address($outputlangs,$this->emetteur);

			// Show sender
			$posy=42;
		 	$posx=$this->marge_gauche;
			if (! empty($conf->global->MAIN_INVERT_SENDER_RECIPIENT)) $posx=$this->page_largeur-$this->marge_droite-80;
			$hautcadre=40;

			// Show sender frame
			$pdf->SetTextColor(0,0,0);
			$pdf->SetFont('','', $default_font_size - 2);
			$pdf->SetXY($posx,$posy-5);
			//$pdf->MultiCell(66,5, $outputlangs->transnoentities("BillFrom").":", 0, 'L');
			$pdf->SetXY($posx,$posy);
			$pdf->SetFillColor(230,230,230);
			$pdf->MultiCell(82, $hautcadre, "", 0, 'R', 1);
			$pdf->SetTextColor(0,0,60);

			// Show sender name
			$pdf->SetXY($posx+2,$posy+3);
			$pdf->SetFont('','B', $default_font_size);
			$pdf->MultiCell(80, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
			$posy=$pdf->getY();

			// Show sender information
			$pdf->SetXY($posx+2,$posy);
			$pdf->SetFont('','', $default_font_size - 1);
			$pdf->MultiCell(80, 4, $carac_emetteur, 0, 'L');


			// If CUSTOMER contact defined, we use it
			$usecontact=false;
			$arrayidcontact=$object->getIdContact('external','CUSTOMER');
			if (count($arrayidcontact) > 0)
			{
				$usecontact=true;
				$result=$object->fetch_contact($arrayidcontact[0]);
			}

			// Recipient name
			if (! empty($usecontact))
			{
				// On peut utiliser le nom de la societe du contact
				if ($conf->global->MAIN_USE_COMPANY_NAME_OF_CONTACT) $socname = $object->contact->socname;
				else $socname = $object->thirdparty->nom;
				$carac_client_name=$outputlangs->convToOutputCharset($socname);
			}
			else
			{
				$carac_client_name=$outputlangs->convToOutputCharset($object->thirdparty->nom);
			}

			$carac_client=pdf_build_address($outputlangs,$this->emetteur,$object->thirdparty,$object->contact,$usecontact,'target');

			// Show recipient
			$posy=42;
			$posx=$this->page_largeur-$this->marge_droite-100;
			if (! empty($conf->global->MAIN_INVERT_SENDER_RECIPIENT)) $posx=$this->marge_gauche;

			// Show recipient frame
			$pdf->SetTextColor(0,0,0);
			$pdf->SetFont('','', $default_font_size - 2);
			$pdf->SetXY($posx,$posy-5);
			$pdf->MultiCell(100, 4, $outputlangs->transnoentities("BillTo").":", 0, 'L');
			$pdf->rect($posx, $posy, 100, $hautcadre);
			$pdf->SetTextColor(0,0,0);

			// Show recipient name
			$pdf->SetXY($posx+2,$posy+3);
			$pdf->SetFont('','B', $default_font_size);
			$pdf->MultiCell(100,4, $carac_client_name, 0, 'L');

			// Show recipient information
			$pdf->SetFont('','', $default_font_size - 1);
			$pdf->SetXY($posx+2,$posy+8);
			$pdf->MultiCell(100,4, $carac_client, 0, 'L');
		}
	}

	/**
	 *   	\brief      Show footer of page
	 *   	\param      pdf     		PDF factory
	 * 		\param		object			Object invoice
	 *      \param      outputlangs		Object lang for output
	 * 		\remarks	Need this->emetteur object
	 */
	function _pagefoot(&$pdf,$object,$outputlangs)
	{
		return pdf_pagefoot($pdf,$outputlangs,'PROPALE_FREE_TEXT',$this->emetteur,$this->marge_basse,$this->marge_gauche,$this->page_hauteur,$object);
	}

}

?>
