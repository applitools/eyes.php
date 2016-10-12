<?php
/*
 * Applitools SDK for Selenium integration.
 */
/**
 * Provides image compression based on delta between consecutive images.
 */
    class ImageDeltaCompressor {

    //private static final byte[] PREAMBLE;
    const COMPRESS_BY_RAW_BLOCKS_FORMAT = 3;
//FIXME
    // Init the preamble (needs to be in a static init block since we must
    // handle encoding exception).
    /*static {
        byte[] preambleBytes;
        try {
            preambleBytes = "applitools".getBytes("UTF-8");
        } catch (UnsupportedEncodingException e) {
            // Use the default charset. Less desirable, but should work
            // in most cases.
            preambleBytes = "applitools".getBytes();
        }

        PREAMBLE = preambleBytes;
    }*/
    
    /**
     * Computes the width and height of the image data contained in the block
     * at the input column and row.
     * @param imageSize The image size in pixels.
     * @param blockSize The block size for which we would like to compute the
     *                  image data width and height.
     * @param blockColumn The block column index
     * @param blockRow The block row index
     * @return The width and height of the image data contained in the block.
     */
    private static function getActualBlockSize(Dimension $imageSize,
            $blockSize, $blockColumn, $blockRow) {
        $actualWidth = Math::min($imageSize->width - ($blockColumn * $blockSize),
                $blockSize);
        $actualHeight = Math::min($imageSize->height - ($blockRow * $blockSize),
                                $blockSize);

        return new Dimension($actualWidth, $actualHeight);
    }

    /**
     * Compares a block of pixels between the source and target images.
     * @param sourcePixels The pixels of the source block.
     * @param targetPixels The pixels of the target block
     * @param imageSize The image size in pixels.
     * @param pixelLength Bytes per pixel. Since pixel might include alpha.
     * @param blockSize The block size in pixels.
     * @param blockColumn The column index of the block to compare.
     * @param blockRow The row index of the block to compare.
     * @param channel The channel for which we compare the blocks
     * @return Whether the source and target blocks are identical,
     * and a copy of the target block's channel bytes.
     */

    private static function CompareAndCopyBlockChannelData(
                $sourcePixels, $targetPixels,
                Dimension $imageSize, $pixelLength, $blockSize,
            $blockColumn, $blockRow, $channel) {

        $isIdentical = true; // initial default

        // Getting the actual amount of data in the block we wish to copy
        $actualBlockSize = self::getActualBlockSize($imageSize, $blockSize, $blockColumn, $blockRow);

        $actualBlockHeight = $actualBlockSize->height;
        $actualBlockWidth = $actualBlockSize->width;

        $stride = $imageSize->width * $pixelLength;

        // The number of bytes actually contained in the block for the
        // current channel (might be less than blockSize*blockSize)
        $channelBytes = $actualBlockHeight*$actualBlockWidth; //FIXME need to check
        $channelBytesOffset = 0;

        // Actually comparing and copying the pixels
        for ($h = 0; $h < $actualBlockHeight; ++$h) {
            $offset = ((($blockSize * $blockRow) + $h) * $stride) +
                    ($blockSize * $blockColumn * $pixelLength) + $channel;
            for ($w = 0; $w < $actualBlockWidth; ++$w) {
                $sourceByte = $sourcePixels[$offset];
                $targetByte = $targetPixels[$offset];
                if ($sourceByte != $targetByte) {
                    $isIdentical = false;
                }

                $channelBytes[$channelBytesOffset++] = $targetByte;
                $offset += $pixelLength;
            }
        }

        return new CompareAndCopyBlockChannelDataResult($isIdentical,
                $channelBytes);
    }

    /**
     * Compresses a target image based on a difference from a source image.
     *
     * @param target The image we want to compress.
     * @param targetEncoded The image we want to compress in its png bytes
     *                      representation.
     * @param source The baseline image by which a compression will be
     *               performed.
     * @param blockSize How many pixels per block.
     * @return The compression result, or the {@code targetEncoded} if the
     * compressed bytes count is greater than the uncompressed bytes count.
     * @throws java.io.IOException If there was a problem reading/writing
     * from/to the streams which are created during the process.
     */
    public static function compressByRawBlocks(Gregwar\Image\Image $target,
            $targetEncoded, Gregwar\Image\Image $source, $blockSize = 30 ){
//FIXME need to find suitable solution depends on "BufferedImage"
        // If there's no image to compare to, or the images are in different
        // sizes, we simply return the encoded target.
        if ($source == null
                || ($source->getWidth() != $target->getWidth())
                || ($source->getHeight() != $target->getHeight())) {
            return $targetEncoded;
        }

        // IMPORTANT: Notice that the pixel bytes are (A)RGB!
        $targetPixels =
            /*((DataBufferByte) */$target->getRaster()->getDataBuffer()->getData();
        $sourcePixels =
                /*((DataBufferByte)*/ $source->getRaster()->getDataBuffer()->getData();

        // The number of bytes comprising a pixel (depends if there's an
        // Alpha channel).
        $pixelLength = ($target->getAlphaRaster() != null) ? 4 : 3;
        $imageSize = new Dimension($target->getWidth(),
                                            $target->getHeight());

        // Calculating how many block columns and rows we've got.
        $blockColumnsCount = ($target->getWidth() / $blockSize)
                + (($target->getWidth() % $blockSize) == 0 ? 0 : 1);
        $blockRowsCount = ($target->getHeight() / $blockSize)
                + (($target->getHeight() % $blockSize) == 0 ? 0 : 1);
        
        // We'll use a stream for the compression.
        $resultStream = new ByteArrayOutputStream();
        $resultCountingStream =
                new CountingOutputStream($resultStream);
        // Since we need to write "short" and other variations.
        $resultDataOutputStream =
                new DataOutputStream($resultCountingStream);
        // This will be used for doing actual data compression
        $compressed =
                new DeflaterOutputStream($resultCountingStream,
                        new Deflater(Deflater::BEST_COMPRESSION,true));

        $compressedDos = new DataOutputStream($compressed);

        // Writing the header
        $resultStream->write(PREAMBLE, 0, PREAMBLE.length );//FIXME
        $resultStream->write(COMPRESS_BY_RAW_BLOCKS_FORMAT);
        // since we don't have a source ID, we write 0 length (Big endian).
        $resultDataOutputStream->writeShort(0);

        // Writing the block size (Big endian)
        $resultDataOutputStream->writeShort($blockSize);

        for ($channel = 0; $channel < 3; ++$channel) {

            // The image is RGB, so all that's left is to skip the Alpha
            // channel if there is one.
            $actualChannelIndex = ($pixelLength == 4) ? $channel + 1 : $channel;

            $blockNumber = 0;
            for ($blockRow = 0; $blockRow < $blockRowsCount; ++$blockRow) {
                for ($blockColumn = 0; $blockColumn < $blockColumnsCount;
                        ++$blockColumn) {

                    $compareResult = CompareAndCopyBlockChannelData
                            ($sourcePixels, $targetPixels, $imageSize,
                                    $pixelLength, $blockSize, $blockColumn,
                                    $blockRow, $actualChannelIndex);

                    if (!$compareResult->getIsIdentical()) {
                        $compressed.write($channel);
                        $compressedDos.writeInt($blockNumber); // Big endian
                        $channelBytes = $compareResult->getBuffer();
                        $compressed->write($channelBytes, 0, $channelBytes->length);

                        // If the number of bytes already written is greater
                        // then the number of bytes for the uncompressed
                        // target, we just return the uncompressed target.
                        if ($resultCountingStream->getBytesCount()
                            > $targetEncoded->length) {
                            $compressedDos->close();
                            return Arrays::copyOf($targetEncoded,
                                                    $targetEncoded->length);
                        }
                    }

                    ++$blockNumber;
                }
            }
        }
        $compressedDos->close(); // flushing + closing the compression.

        if ($resultCountingStream->getBytesCount() > $targetEncoded->length) {
            return $targetEncoded;
        }

        return $resultStream->toByteArray();
    }

}