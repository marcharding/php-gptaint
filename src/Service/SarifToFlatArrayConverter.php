<?php

namespace App\Service;

class SarifToFlatArrayConverter
{
    public function getArray(array $sarifResult): array
    {
        if (count($sarifResult['runs'][0]['results']) === 0) {
            return [];
        }

        $threadFlows = [];

        foreach ($sarifResult['runs'][0]['results'] as $result) {
            $threadFlow = [];
            $threadFlow['id'] = $result['ruleId'];
            $threadFlow['description'] = $result['message']['text'];
            $artifactLocationUri = $result['locations'][0]['physicalLocation'];

            foreach ($result['codeFlows'][0]['threadFlows'] as $location) {
                $linenumbersPerFile = [];

                foreach ($location['locations'] as $loc) {
                    $url = $loc['location']['physicalLocation']['artifactLocation']['uri'];

                    // skip .phpstub entries
                    if (strpos($url, '.phpstub') !== false) {
                        continue;
                    }

                    // we only need the last git inside a file, the path inside a file is resolved via the code extractor
                    $linenumbersPerFile = array_filter($linenumbersPerFile, function ($entry) use ($result, $url) {
                        return strpos($entry, $result['ruleId'].'_'.$url.':') !== 0;
                    }, ARRAY_FILTER_USE_KEY);

                    $lineNumber = $loc['location']['physicalLocation']['region']['startLine'];
                    $columnNumber = $loc['location']['physicalLocation']['region']['startColumn'];

                    // sometime the linenumbers of the last result of the thread flow and ht
                    if ($url === $artifactLocationUri['artifactLocation']['uri']) {
                        $lineNumber = $artifactLocationUri['region']['startLine'];
                        $columnNumber = $artifactLocationUri['region']['startColumn'];
                    }

                    $linenumbersPerFile[$result['ruleId'].'_'.$url.":$lineNumber:$columnNumber"] = [
                        'file' => $url,
                        'region' => $loc['location']['physicalLocation']['region'],
                    ];

                    $threadFlow['locations'] = $linenumbersPerFile;
                }

                // use the last location of the current issue/threat as a index for the array, so we can map the psalm flow to this.
                $key = array_key_last($threadFlow['locations']);

                $threadFlows[$key] = $threadFlow;
            }
        }

        return $threadFlows;
    }
}
