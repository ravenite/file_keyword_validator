<?php

namespace Drupal\file_keyword_validator\Service;

use Drupal\file\Entity\File;
use Smalot\PdfParser\Parser as PdfParser;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service to handle document text extraction and page counting.
 */
class TextExtractorService {

  /**
   * Bytes read at a time when scanning a file directly.
   */
  protected const READ_CHUNK_SIZE = 1048576;

  /**
   * Bytes carried between chunks, and the size of a PDF's linearized header.
   */
  protected const READ_OVERLAP = 1024;

  public function __construct(
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * Determine a PDF's page count by reading it, without parsing the document.
   *
   * Linearized PDFs state the count in a dictionary at the start of the file.
   * Otherwise it is the /Count of the page tree root, which can be anywhere,
   * so the file is read in chunks to keep memory use flat.
   *
   * @return int|null
   *   The page count, or NULL if the file doesn't expose it. That is normal for
   *   PDFs that keep their page tree in a compressed object stream.
   */
  public function getPdfPageCount(File $file): ?int {
    $real_path = $this->fileSystem->realpath($file->getFileUri());
    $handle = $real_path && file_exists($real_path) ? fopen($real_path, 'rb') : FALSE;
    if (!$handle) {
      return NULL;
    }

    $head = (string) fread($handle, self::READ_OVERLAP);
    if (preg_match('#/Linearized\s.*?/N\s+(\d+)#s', $head, $match)) {
      fclose($handle);
      return (int) $match[1];
    }

    // Each read keeps the end of the last one, so a dictionary that spans two
    // chunks is still matched. Intermediate page tree nodes have lower counts
    // than the root, so the highest count is the total.
    $count = NULL;
    $buffer = $head;
    do {
      if (preg_match_all('#(?|/Type\s*/Pages\b[^>]*?/Count\s+(\d+)|/Count\s+(\d+)[^>]*?/Type\s*/Pages\b)#', $buffer, $matches)) {
        $count = max($count ?? 0, ...array_map('intval', $matches[1]));
      }
      $buffer = substr($buffer, -self::READ_OVERLAP) . fread($handle, self::READ_CHUNK_SIZE);
    } while (strlen($buffer) > self::READ_OVERLAP);

    fclose($handle);
    return $count;
  }

  /**
   * Extract plain text content from target file extensions.
   */
  public function extractText(File $file): string {
    $real_path = $this->fileSystem->realpath($file->getFileUri());
    if (!$real_path || !file_exists($real_path)) {
      return '';
    }

    $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));

    return match ($extension) {
      'pdf' => $this->extractPdfText($real_path),
      'docx' => $this->extractOfficeXmlText($real_path, '#^word/(document|header\d*|footer\d*|footnotes|endnotes|comments)\.xml$#'),
      'pptx' => $this->extractOfficeXmlText($real_path, '#^ppt/(slides|notesSlides)/[^/]+\.xml$#'),
      'xlsx' => $this->extractOfficeXmlText($real_path, '#^xl/(sharedStrings|comments\d*|worksheets/[^/]+)\.xml$#'),
      'doc', 'ppt', 'xls' => $this->extractLegacyOfficeText($real_path),
      default => '',
    };
  }

  /**
   * Extract text from a PDF. The whole document is parsed in memory.
   */
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

  /**
   * Extract text from the matching XML parts of an Office Open XML file.
   *
   * Each part is streamed out of the ZIP, so the document is never loaded
   * whole. These formats keep text in <t> elements, plus <v> for spreadsheet
   * cell values. A word can be split across several elements, so only the end
   * of a paragraph, shared string or cell adds a space.
   */
  protected function extractOfficeXmlText(string $path, string $part_pattern): string {
    try {
      $zip = new \ZipArchive();
      if ($zip->open($path, \ZipArchive::RDONLY) !== TRUE) {
        throw new \RuntimeException('The file is not a valid ZIP archive.');
      }

      $parts = [];
      for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match($part_pattern, $name)) {
          $parts[] = $name;
        }
      }
      $zip->close();
      natsort($parts);

      $fullText = '';
      foreach ($parts as $part) {
        $reader = new \XMLReader();
        $reader->open('zip://' . $path . '#' . $part, NULL, LIBXML_NONET);
        while ($reader->read()) {
          if ($reader->nodeType === \XMLReader::ELEMENT && in_array($reader->localName, ['t', 'v'], TRUE)) {
            $fullText .= $reader->readString();
          }
          elseif ($reader->nodeType === \XMLReader::END_ELEMENT && in_array($reader->localName, ['p', 'si', 'c'], TRUE)) {
            $fullText .= ' ';
          }
        }
        $reader->close();
      }
      return $fullText;
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('file_keyword_validator')->error('Office document extraction failed: @msg', ['@msg' => $e->getMessage()]);
      return '';
    }
  }

  /**
   * Extract text from a legacy binary .doc, .ppt or .xls file.
   *
   * These formats hold text as runs of 8-bit or UTF-16 characters. Rather than
   * parse the format, the file is read in chunks and readable runs in either
   * encoding are kept. That is enough for keyword matching, and it also
   * includes the file's metadata. PHPWord's MsDoc reader is not used because
   * it decodes 8-bit text as UTF-16, so keywords would never match.
   */
  protected function extractLegacyOfficeText(string $path): string {
    $handle = fopen($path, 'rb');
    if (!$handle) {
      $this->loggerFactory->get('file_keyword_validator')->error('Legacy Office extraction failed: could not read the file.');
      return '';
    }

    $fullText = '';
    $buffer = '';
    do {
      $buffer = substr($buffer, -self::READ_OVERLAP) . (string) fread($handle, self::READ_CHUNK_SIZE);
      // A run must start with plain ASCII, and accented characters are only
      // kept between ASCII characters. Binary data such as an embedded image
      // would otherwise pass as text, and most of the file would be kept.
      if (preg_match_all('#[\x09\x0A\x0D\x20-\x7E]{5,}(?:[\xA0-\xFF][\x09\x0A\x0D\x20-\x7E]+)*#', $buffer, $matches)) {
        $fullText .= ' ' . mb_convert_encoding(implode(' ', $matches[0]), 'UTF-8', 'Windows-1252');
      }
      if (preg_match_all('#(?:[\x09\x0A\x0D\x20-\x7E\xA0-\xFF]\x00){4,}#', $buffer, $matches)) {
        $fullText .= ' ' . mb_convert_encoding(implode(" \x00", $matches[0]), 'UTF-8', 'UTF-16LE');
      }
    } while (!feof($handle));

    fclose($handle);
    return $fullText;
  }

}
