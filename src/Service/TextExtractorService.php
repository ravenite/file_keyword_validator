<?php

namespace Drupal\file_keyword_validator\Service;

use Drupal\file\Entity\File;
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service to handle document text extraction and page counting.
 */
class TextExtractorService {

  public function __construct(
    protected LoggerChannelFactoryInterface $loggerFactory
  ) {}

  /**
   * Determine page count for PDF files without parsing full text into memory.
   */
  public function getPdfPageCount(File $file): int {
    $real_path = \Drupal::service('file_system')->realpath($file->getFileUri());
    if (!$real_path || !file_exists($real_path)) {
      return 0;
    }

    try {
      $parser = new PdfParser();
      $pdf = $parser->parseFile($real_path);
      return count($pdf->getPages());
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('file_keyword_validator')->error('PDF page count failed: @msg', ['@msg' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * Extract plain text content from target file extensions.
   */
  public function extractText(File $file): string {
    $real_path = \Drupal::service('file_system')->realpath($file->getFileUri());
    if (!$real_path || !file_exists($real_path)) {
      return '';
    }

    $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));

    return match ($extension) {
      'pdf' => $this->extractPdfText($real_path),
      'docx', 'doc' => $this->extractWordText($real_path),
      'xlsx', 'xls' => $this->extractExcelText($real_path),
      default => '',
    };
  }

  protected function extractPdfText(string $path): string {
    try {
      $parser = new PdfParser();
      $pdf = $parser->parseFile($path);
      return $pdf->getText();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('file_keyword_validator')->error('PDF extraction failed: @msg', ['@msg' => $e->getMessage()]);
      return '';
    }
  }

  protected function extractWordText(string $path): string {
    try {
      $phpWord = WordIOFactory::load($path);
      $fullText = '';
      foreach ($phpWord->getSections() as $section) {
        foreach ($section->getElements() as $element) {
          if (method_exists($element, 'getText')) {
            $fullText .= ' ' . $element->getText();
          }
        }
      }
      return $fullText;
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('file_keyword_validator')->error('Word document extraction failed: @msg', ['@msg' => $e->getMessage()]);
      return '';
    }
  }

  protected function extractExcelText(string $path): string {
    try {
      $spreadsheet = SpreadsheetIOFactory::load($path);
      $fullText = '';
      foreach ($spreadsheet->getAllSheets() as $worksheet) {
        foreach ($worksheet->getRowIterator() as $row) {
          $cellIterator = $row->getCellIterator();
          $cellIterator->setIterateOnlyExistingCells(TRUE);
          foreach ($cellIterator as $cell) {
            $fullText .= ' ' . $cell->getFormattedValue();
          }
        }
      }
      return $fullText;
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('file_keyword_validator')->error('Excel extraction failed: @msg', ['@msg' => $e->getMessage()]);
      return '';
    }
  }

}
