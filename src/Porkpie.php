<?php

/**
 * This file is part of Islandora.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * PHP Version 5.5.9
 *
 * @category Islandora
 * @package  Islandora
 * @author   Daniel Lamb <daniel@discoverygarden.ca>
 * @license  http://www.gnu.org/licenses/gpl-3.0.en.html GPL
 * @link     http://www.islandora.ca
 */

namespace Islandora\Porkpie;

use Islandora\Chullo\IFedoraClient;

class Porkpie
{

    protected $fedora;  // IFedoraClient

    public function __construct(IFedoraClient $fedora)
    {
        $this->fedora = $fedora;
    }

    /**
     * Creates a new pcdm:Collection in Fedora.
     *
     * @param string    $uri            Resource URI
     * @param string    $content        String or binary content
     * @param array     $headers        HTTP Headers
     * @param string    $transaction    Transaction id
     * @param string    $checksum       SHA-1 checksum
     *
     * @return string   Uri of newly created resource
     */
    public function createCollection(
        $uri = "",
        $content = "",
        $headers = [],
        $transaction = "",
        $checksum = ""
    ) {
        // Open a transaction if none has been provided.
        $needsTransaction = empty($transaction);
        if ($needsTransaction) {
            $transaction = $this->fedora->createTransaction();
        }

        try {
            // Generate default rdf if none has been provided.
            if (empty($content)) {
                $content = <<<EOD
                    @prefix pcdm: <http://pcdm.org/models#>

                    <> a pcdm:Collection
EOD;
                $headers['Content-Type'] = 'text/turtle';
                $checksum = sha1($content);
            }

            // Create the collection.
            $collection_uri = $this->fedora->createResource(
                $uri,
                $content,
                $headers,
                $transaction,
                $checksum
            );

            // Create the 'members' indirect container.
            $members_container_rdf = <<<EOD
                @prefix ldp: <http://www.w3.org/ns/ldp#>
                @prefix pcdm: <http://pcdm.org/models#>
                @prefix ore: <http://www.openarchives.org/ore/terms/>

                <> a ldp:IndirectContainer ;
                    ldp:membershipResource <$collection_uri> ;
                    ldp:hasMemberRelation pcdm:hasMember ;
                    ldp:insertedContentRelation ore:proxyFor .
EOD;
            $mimetype = 'text/turtle';

            $this->fedora->createResource(
                $collection_uri,
                $members_container_rdf,
                ['Content-Type' => $mimetype],
                $transaction,
                sha1($members_container_rdf)
            );

            // Commit if needed, and return a uri for the representation
            // outside of the transaction.
            if ($needsTransaction) {
                $this->fedora->commitTransaction($transaction);
                return $this->stripTransactionFromUri($collection_uri);
            }

            return $collection_uri;
        } catch (\Exception $e) {
            $this->fedora->rollbackTransaction($transaction);
            return null;
        }
    }

    /**
     * Creates a new pcdm:Object in Fedora.
     *
     * @param string    $uri            Resource URI
     * @param string    $content        String or binary content
     * @param array     $headers        HTTP Headers
     * @param string    $transaction    Transaction id
     * @param string    $checksum       SHA-1 checksum
     *
     * @return string   Uri of newly created resource
     */
    public function createObject(
        $uri = "",
        $content = "",
        $headers = [],
        $transaction = "",
        $checksum = ""
    ) {
        // Open a transaction if none has been provided.
        $needsTransaction = empty($transaction);
        if ($needsTransaction) {
            $transaction = $this->fedora->createTransaction();
        }

        try {
            // Generate default rdf if none has been provided.
            if (empty($content)) {
                $content = <<<EOD
                    @prefix pcdm: <http://pcdm.org/models#>

                    <> a pcdm:Object
EOD;
                $headers['Content-Type'] = 'text/turtle';
                $checksum = sha1($content);
            }

            // Create the object.
            $object_uri = $this->fedora->createResource(
                $uri,
                $content,
                $headers,
                $transaction,
                $checksum
            );

            // Create the 'members' indirect container.
            $members_container_rdf = <<<EOD
                @prefix ldp: <http://www.w3.org/ns/ldp#>
                @prefix pcdm: <http://pcdm.org/models#>
                @prefix ore: <http://www.openarchives.org/ore/terms/>

                <> a ldp:IndirectContainer ;
                    ldp:membershipResource <$object_uri> ;
                    ldp:hasMemberRelation pcdm:hasMember ;
                    ldp:insertedContentRelation ore:proxyFor .
EOD;
            $mimetype = 'text/turtle';

            $this->fedora->createResource(
                $object_uri,
                $members_container_rdf,
                ['Content-Type' => $mimetype],
                $transaction,
                sha1($members_container_rdf)
            );

            // Create the 'files' direct container.
            $files_container_rdf = <<<EOD
                @prefix ldp: <http://www.w3.org/ns/ldp#>
                @prefix pcdm: <http://pcdm.org/models#>

                <> a ldp:DirectContainer ;
                    ldp:membershipResource <$object_uri> ;
                    ldp:hasMemberRelation pcdm:hasFile .
EOD;

            $this->fedora->createResource(
                $object_uri,
                $files_container_rdf,
                ['Content-Type' => $mimetype],
                $transaction,
                sha1($files_container_rdf)
            );

            // Commit if needed, and return a uri for the representation
            // outside of the transaction.
            if ($needsTransaction) {
                $this->fedora->commitTransaction($transaction);
                return $this->stripTransactionFromUri($object_uri);
            }

            return $object_uri;
        } catch (\Exception $e) {
            echo "EXCEPTION!";
            $this->fedora->rollbackTransaction($transaction);
            return null;
        }
    }

    /**
     * Removes the transaction id from a uri.
     *
     * @param string    $uri            Resource URI
     *
     * @return string   Uri without a transaction
     */
    protected function stripTransactionFromUri($uri)
    {
        // Gross...
        $split = preg_split("|(tx:[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12})|", $uri);
        if (count($split) == 2) {
            return rtrim($split[0], '/') . '/' . ltrim($split[1], '/');
        } else {
            return $uri;
        }
    }

    /**
     * Gets the indirect container for members in an Object or Collection.
     *
     * @param string    $uri            Object or Collection URI
     * @param string    $transaction    Transaction id
     *
     * @return string   Uri of members container
     */
    protected function getMembersContainer($uri, $transaction = "")
    {
        $graph = $this->fedora->getGraph(
            $uri,
            ['Prefer' => 'return=representation; include="http://fedora.info/definitions/v4/repository#EmbedResources"'],
            $transaction
        );
        $resources = $graph->allOfType("ldp:IndirectContainer");
        foreach ($resources as $resource) {
            $relation = $resource->get("ldp:hasMemberRelation");
            $is_members_container = strcmp($relation, "http://pcdm.org/models#hasMember") == 0;
            if ($is_members_container) {
                return $resource->getUri();
            }
        }
        return null;
    }

    protected function getFilesContainer($uri, $transaction = "")
    {
        $graph = $this->fedora->getGraph(
            $uri,
            ['Prefer' => 'return=representation; include="http://fedora.info/definitions/v4/repository#EmbedResources"'],
            $transaction
        );
        $resources = $graph->allOfType("ldp:DirectContainer");
        foreach ($resources as $resource) {
            $relation = $resource->get("ldp:hasMemberRelation");
            $is_members_container = strcmp($relation, "http://pcdm.org/models#hasFile") == 0;
            if ($is_members_container) {
                return $resource->getUri();
            }
        }
        return null;
    }

    public function addMember($parent_uri, $child_uri, $transaction = "") {
        $container_uri = $this->getMembersContainer($parent_uri, $transaction);

        if (empty($container_uri)) {
            return null;
        }

        $rdf = <<<EOD
            @prefix ore: <http://www.openarchives.org/ore/terms/>

            <> ore:proxyFor <$child_uri> ;
               ore:proxyIn  <$parent_uri> .
EOD;

        return $this->fedora->createResource(
            $container_uri,
            $rdf,
            ['Content-Type' => 'text/turtle'],
            $transaction,
            sha1($rdf)
        );
    }

    public function addFile($parent_uri, $binary, $mimetype, $sparql = "", $transaction = "") {
        $needsTransaction = empty($transaction);
        if ($needsTransaction) {
            $transaction = $this->fedora->createTransaction();
        }

        try {
            $container_uri = $this->getFilesContainer($parent_uri, $transaction);

            if (empty($container_uri)) {
                return null;
            }

            $file_uri = $this->fedora->createResource(
                $container_uri,
                $binary,
                ['Content-Type' => $mimetype],
                $transaction
            );

            if (empty($sparql)) {
                $sparql = <<<EOD
                    PREFIX pcdm: <http://pcdm.org/models#>

                    INSERT DATA {
                          <> a pcdm:File .
                    };
EOD;
            }

            $this->fedora->modifyResource(
                "$file_uri/fcr:metadata",
                $sparql,
                [],
                $transaction
            );

            // Commit if needed, and return a uri for the representation
            // outside of the transaction.
            if ($needsTransaction) {
                $this->fedora->commitTransaction($transaction);
                return $this->stripTransactionFromUri($file_uri);
            }

            return $file_uri;
        } catch (\Exception $exception) {
            if (!empty($transaction)) {
                $this->fedora->rollbackTransaction($transaction);
            }
            return null;
        }
    }

    public function addPreservationMaster($parent_uri, $binary, $mimetype, $transaction = "") {
        $sparql = <<<EOD
            PREFIX pcdm: <http://pcdm.org/models#>
            PREFIX pcdmuse: <http://pcdm.org/use#>

            INSERT DATA {
                  <> a pcdm:File .
                  <> a pcdmuse:PreservationMasterFile .
            };
EOD;

        return $this->addFile(
            $parent_uri,
            $binary,
            $mimetype,
            $sparql,
            $transaction
        );
    }

    public function addThumbnail($parent_uri, $binary, $mimetype, $transaction = "") {
        $sparql = <<<EOD
            PREFIX pcdm: <http://pcdm.org/models#>
            PREFIX pcdmuse: <http://pcdm.org/use#>

            INSERT DATA {
                  <> a pcdm:File .
                  <> a pcdmuse:ThumbnailImage .
            };
EOD;

        return $this->addFile(
            $parent_uri,
            $binary,
            $mimetype,
            $sparql,
            $transaction
        );
    }

    public function addServiceFile($parent_uri, $binary, $mimetype, $transaction = "") {
        $sparql = <<<EOD
            PREFIX pcdm: <http://pcdm.org/models#>
            PREFIX pcdmuse: <http://pcdm.org/use#>

            INSERT DATA {
                  <> a pcdm:File .
                  <> a pcdmuse:ServiceFile .
            };
EOD;

        return $this->addFile(
            $parent_uri,
            $binary,
            $mimetype,
            $sparql,
            $transaction
        );
    }

    public function addExtractedText($parent_uri, $binary, $mimetype, $transaction = "") {
        $sparql = <<<EOD
            PREFIX pcdm: <http://pcdm.org/models#>
            PREFIX pcdmuse: <http://pcdm.org/use#>

            INSERT DATA {
                  <> a pcdm:File .
                  <> a pcdmuse:ExtractedText .
            };
EOD;

        return $this->addFile(
            $parent_uri,
            $binary,
            $mimetype,
            $sparql,
            $transaction
        );
    }

    public function addTranscript($parent_uri, $binary, $mimetype, $transaction = "") {
        $sparql = <<<EOD
            PREFIX pcdm: <http://pcdm.org/models#>
            PREFIX pcdmuse: <http://pcdm.org/use#>

            INSERT DATA {
                  <> a pcdm:File .
                  <> a pcdmuse:Transcript .
            };
EOD;

        return $this->addFile(
            $parent_uri,
            $binary,
            $mimetype,
            $sparql,
            $transaction
        );
    }

    public function addOriginalFile($parent_uri, $binary, $mimetype, $transaction = "") {
        $sparql = <<<EOD
            PREFIX pcdm: <http://pcdm.org/models#>
            PREFIX pcdmuse: <http://pcdm.org/use#>

            INSERT DATA {
                  <> a pcdm:File .
                  <> a pcdmuse:OriginalFile .
            };
EOD;

        return $this->addFile(
            $parent_uri,
            $binary,
            $mimetype,
            $sparql,
            $transaction
        );
    }

    public function addIntermediateFile($parent_uri, $binary, $mimetype, $transaction = "") {
        $sparql = <<<EOD
            PREFIX pcdm: <http://pcdm.org/models#>
            PREFIX pcdmuse: <http://pcdm.org/use#>

            INSERT DATA {
                  <> a pcdm:File .
                  <> a pcdmuse:IntermediateFile .
            };
EOD;

        return $this->addFile(
            $parent_uri,
            $binary,
            $mimetype,
            $sparql,
            $transaction
        );
    }

    public function addNonRdfDescriptiveMetadata($parent_uri, $metadata, $mimetype, $standard, $transaction = "") {
        $sparql = <<<EOD
            PREFIX pcdm: <http://pcdm.org/models#>
            PREFIX dc: <http://purl.org/dc/terms/>

            INSERT DATA {
                  <> a pcdm:File .
                  <> dc:conformsTo "$standard"
            };
EOD;

        return $this->addFile(
            $parent_uri,
            $metadata,
            $mimetype,
            $sparql,
            $transaction
        );
    }
}
