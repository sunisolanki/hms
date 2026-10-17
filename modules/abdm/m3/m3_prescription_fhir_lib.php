<?php
require_once __DIR__ . '/../../../includes/db.php';
date_default_timezone_set('Asia/Kolkata');

/*
 * Presentation is the caller's responsibility.
 *
 * This function used to echo the JSON body and call exit(), which made
 * m3_prescription_fhir_build_bundle() unusable as a library: it terminated
 * the calling process instead of returning, so no caller could inspect or
 * post-process the result. The compatibility wrapper
 * (m3_prescription_fhir.php) is the only place that echoes and exits.
 *
 * The returned array shape is unchanged:
 *   ['success' => bool, 'message' => string, ...$data]
 */
function jsonResponse($success, $message, $data = [])
{
    return [
        'success' => $success,
        'message' => $message,
        ...$data
    ];
}

function sqlDateIsValid($date)
{
    if (!$date) {
        return false;
    }
    $date = trim((string)$date);
    if ($date === '' || $date === '0000-00-00') {
        return false;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $dt = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dt) {
        return false;
    }
    $errors = DateTime::getLastErrors();
    if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
        return false;
    }
    return $dt->format('Y-m-d') === $date;
}

function localDateTime($dateTime)
{
    if (!$dateTime) {
        return null;
    }
    $dateTime = trim((string)$dateTime);
    if ($dateTime === '' || $dateTime === '0000-00-00 00:00:00') {
        return null;
    }
    try {
        $dt = new DateTime($dateTime, new DateTimeZone('Asia/Kolkata'));
        return $dt->format('Y-m-d\TH:i:sP');
    } catch (Exception $e) {
        return null;
    }
}

function makeReference($type, $id)
{
    return 'urn:uuid:' . $type . '-' . (int)$id;
}

function cleanText($value)
{
    return trim((string)($value ?? ''));
}

function buildNarrative($text)
{
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    return [
        'status' => 'generated',
        'div' => '<div xmlns="http://www.w3.org/1999/xhtml">' . $text . '</div>'
    ];
}

function m3_prescription_fhir_build_bundle(?mysqli $conn, int $prescriptionId, int $appointmentId): array {
    $prescriptionId = (int) $prescriptionId;
    $appointmentId = (int) $appointmentId;

    if ($prescriptionId <= 0 && $appointmentId <= 0) {
        return jsonResponse(false, 'prescription_id or appointment_id is required.');
    }
try {
    if ($prescriptionId > 0) {
        $sql = "
            SELECT
p.*,
a.id AS appointment_id,
a.patient_id AS appointment_patient_id,
a.entity_id AS appointment_doctor_id,
a.type AS appointment_type,
a.appointment_date,
a.appointment_time
FROM opd_prescriptions p
INNER JOIN appointments a ON a.id=p.appointment_id
WHERE p.id=?
LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare prescription query: ' . $conn->error);
        }
        $stmt->bind_param('i', $prescriptionId);
    } else {
        $sql = "
            SELECT
                p.*,
                a.id AS appointment_id,
                a.patient_id AS appointment_patient_id,
                a.doctor_id AS appointment_doctor_id,
                a.appointment_date,
                a.appointment_time
            FROM opd_prescriptions p
            INNER JOIN appointments a ON a.id = p.appointment_id
            WHERE p.appointment_id = ?
            ORDER BY p.id DESC
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare appointment prescription query: ' . $conn->error);
        }
        $stmt->bind_param('i', $appointmentId);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $prescription = $result->fetch_assoc();
    $stmt->close();

    if (!$prescription) {
        return jsonResponse(false, 'Prescription not found.');
    }

   $prescriptionId = (int)$prescription['id'];
$appointmentId = (int)$prescription['appointment_id'];
$patientId = (int)($prescription['appointment_patient_id'] ?? 0);
$doctorId = (int)($prescription['appointment_doctor_id'] ?? 0);

    if ($patientId <= 0) {
        throw new Exception('Patient ID not found for prescription.');
    }

    if ($doctorId <= 0) {
        throw new Exception('Doctor ID not found for prescription.');
    }

    $stmt = $conn->prepare("
        SELECT
            id,
            uhid,
            title,
            first_name,
            last_name,
            sex,
            dob,
            mobile,
            abha_id
        FROM patient_master
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new Exception('Failed to prepare patient query: ' . $conn->error);
    }
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $patientResult = $stmt->get_result();
    $patient = $patientResult->fetch_assoc();
    $stmt->close();

    if (!$patient) {
        throw new Exception('Patient not found.');
    }

    $stmt = $conn->prepare("
        SELECT
            d.id AS doctor_id,
            d.user_id,
            d.license_no,
            u.full_name,
            u.gender,
            u.dob
        FROM doctor_master d
        INNER JOIN users u ON u.id = d.user_id
        WHERE d.user_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new Exception('Failed to prepare doctor query: ' . $conn->error);
    }
    $stmt->bind_param('i', $doctorId);
    $stmt->execute();
    $doctorResult = $stmt->get_result();
    $doctor = $doctorResult->fetch_assoc();
    $stmt->close();

    if (!$doctor) {
        $stmt = $conn->prepare("
            SELECT
                d.id AS doctor_id,
                d.user_id,
                d.license_no,
                u.full_name,
                u.gender,
                u.dob
            FROM doctor_master d
            INNER JOIN users u ON u.id = d.user_id
            WHERE d.id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            throw new Exception('Failed to prepare doctor fallback query: ' . $conn->error);
        }
        $stmt->bind_param('i', $doctorId);
        $stmt->execute();
        $doctorResult = $stmt->get_result();
        $doctor = $doctorResult->fetch_assoc();
        $stmt->close();
    }

    if (!$doctor) {
        throw new Exception('Doctor not found.');
    }

    $stmt = $conn->prepare("
        SELECT
            hospital_id
        FROM abdm_m3_settings
        WHERE environment = 'Sandbox'
        AND status = 1
        ORDER BY id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        throw new Exception('Failed to prepare M3 settings query: ' . $conn->error);
    }
    $stmt->execute();
    $settingsResult = $stmt->get_result();
    $m3Settings = $settingsResult->fetch_assoc();
    $stmt->close();

    $hospitalId = (int)($m3Settings['hospital_id'] ?? 0);

    if ($hospitalId <= 0) {
        $stmt = $conn->prepare("
            SELECT id
            FROM hospital_master
            WHERE status = 1
            ORDER BY id ASC
            LIMIT 1
        ");
        if (!$stmt) {
            throw new Exception('Failed to prepare hospital fallback query: ' . $conn->error);
        }
        $stmt->execute();
        $hospitalResult = $stmt->get_result();
        $hospital = $hospitalResult->fetch_assoc();
        $stmt->close();
        $hospitalId = (int)($hospital['id'] ?? 0);
    }

    if ($hospitalId <= 0) {
        throw new Exception('Hospital not found.');
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM hospital_master
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new Exception('Failed to prepare hospital query: ' . $conn->error);
    }
    $stmt->bind_param('i', $hospitalId);
    $stmt->execute();
    $hospitalResult = $stmt->get_result();
    $hospital = $hospitalResult->fetch_assoc();
    $stmt->close();

    if (!$hospital) {
        throw new Exception('Hospital not found.');
    }

    $stmt = $conn->prepare("
        SELECT
            pm.id,
            pm.item_id,
            pm.frequency,
            pm.meal,
            pm.qty,
            pm.morning,
            pm.noon,
            pm.evening,
            pm.stop_medication,
            pm.stop_date,
            pm.comments,
            im.item_name
        FROM prescribed_medicines pm
        LEFT JOIN item_master im ON im.id = pm.item_id
        WHERE pm.prescription_id = ?
        ORDER BY pm.id ASC
    ");
    if (!$stmt) {
        throw new Exception('Failed to prepare medicine query: ' . $conn->error);
    }
    $stmt->bind_param('i', $prescriptionId);
    $stmt->execute();
    $medicineResult = $stmt->get_result();
    $medicines = [];
    while ($row = $medicineResult->fetch_assoc()) {
        $medicines[] = $row;
    }
    $stmt->close();

    $appointmentDate = cleanText($prescription['appointment_date'] ?? '');
    $appointmentTime = cleanText($prescription['appointment_time'] ?? '');

    if ($appointmentDate !== '') {
        $encounterStartInput = $appointmentDate . ($appointmentTime !== '' ? ' ' . $appointmentTime : ' 00:00:00');
    } else {
        $encounterStartInput = date('Y-m-d H:i:s');
    }

    $encounterStart = localDateTime($encounterStartInput);
    if (!$encounterStart) {
        $encounterStart = date('Y-m-d\TH:i:sP');
    }

    try {
        $encounterStartDt = new DateTime($encounterStart, new DateTimeZone('Asia/Kolkata'));
        $encounterEndDt = clone $encounterStartDt;
        $encounterEndDt->modify('+15 minutes');
        $encounterEnd = $encounterEndDt->format('Y-m-d\TH:i:sP');
    } catch (Exception $e) {
        $encounterEnd = $encounterStart;
    }

    $patientName = trim(
        cleanText($patient['first_name'] ?? '') . ' ' .
        cleanText($patient['last_name'] ?? '')
    );

    if ($patientName === '') {
        $patientName = 'Patient ' . $patientId;
    }

    $doctorName = cleanText($doctor['full_name'] ?? '');
    if ($doctorName === '') {
        $doctorName = 'Doctor ' . $doctorId;
    }

    $hospitalName = cleanText($hospital['name'] ?? $hospital['hospital_name'] ?? '');
    if ($hospitalName === '') {
        $hospitalName = 'Hospital ' . $hospitalId;
    }

    $patientGender = strtolower(cleanText($patient['sex'] ?? ''));
    if (in_array($patientGender, ['m', 'male'], true)) {
        $patientGender = 'male';
    } elseif (in_array($patientGender, ['f', 'female'], true)) {
        $patientGender = 'female';
    } elseif (in_array($patientGender, ['other'], true)) {
        $patientGender = 'other';
    } else {
        $patientGender = 'unknown';
    }

    $compositionId = 'composition-prescription-' . $prescriptionId;
    $patientResourceId = 'patient-' . $patientId;
    $practitionerResourceId = 'practitioner-' . $doctorId;
    $organizationResourceId = 'organization-' . $hospitalId;
    $encounterResourceId = 'encounter-' . $appointmentId;

    $medicationRequests = [];
    $medicationReferences = [];
    $narrativeLines = [];

    foreach ($medicines as $medicine) {
        $medicineId = (int)$medicine['id'];
        $medicationResourceId = 'medication-request-' . $medicineId;

        $itemName = cleanText($medicine['item_name'] ?? '');
        if ($itemName === '') {
            $itemName = 'Medicine ' . (int)($medicine['item_id'] ?? 0);
        }

        $frequency = cleanText($medicine['frequency'] ?? '');
        $meal = cleanText($medicine['meal'] ?? '');

        $morning = is_numeric($medicine['morning'] ?? null) ? (float)$medicine['morning'] : 0;
        $noon = is_numeric($medicine['noon'] ?? null) ? (float)$medicine['noon'] : 0;
        $evening = is_numeric($medicine['evening'] ?? null) ? (float)$medicine['evening'] : 0;
        $qty = is_numeric($medicine['qty'] ?? null) ? (float)$medicine['qty'] : 0;

        $scheduleParts = [];

        if ($frequency !== '') {
            $scheduleParts[] = $frequency;
        }

        if ($meal !== '') {
            $scheduleParts[] = $meal;
        }

        $scheduleParts[] = 'Morning ' . number_format($morning, 2, '.', '');
        $scheduleParts[] = 'Noon ' . number_format($noon, 2, '.', '');
        $scheduleParts[] = 'Evening ' . number_format($evening, 2, '.', '');

        $dosageText = implode(', ', $scheduleParts);

        $dosageInstruction = [
            'text' => $dosageText
        ];

        if ($frequency !== '') {
            $dosageInstruction['timing'] = [
                'code' => [
                    'text' => $frequency
                ]
            ];
        }

        if ($meal !== '') {
            $dosageInstruction['additionalInstruction'] = [
                [
                    'text' => $meal
                ]
            ];
        }

        $nonZeroSlots = [];

        if ($morning > 0) {
            $nonZeroSlots[] = $morning;
        }

        if ($noon > 0) {
            $nonZeroSlots[] = $noon;
        }

        if ($evening > 0) {
            $nonZeroSlots[] = $evening;
        }

        if (count($nonZeroSlots) === 1) {
            $dosageInstruction['doseAndRate'] = [
                [
                    'doseQuantity' => [
                        'value' => $nonZeroSlots[0],
                        'unit' => 'dose'
                    ]
                ]
            ];
        }

        $medicationRequest = [
            'fullUrl' => makeReference('medication-request', $medicineId),
            'resource' => [
                'resourceType' => 'MedicationRequest',
                'id' => $medicationResourceId,
                'meta' => [
                    'profile' => [
                        'https://nrces.in/ndhm/fhir/r4/StructureDefinition/MedicationRequest'
                    ]
                ],
                'status' => !empty($medicine['stop_medication']) ? 'stopped' : 'active',
                'intent' => 'order',
                'medicationCodeableConcept' => [
                    'text' => $itemName
                ],
                'subject' => [
                    'reference' => makeReference('patient', $patientId)
                ],
                'encounter' => [
                    'reference' => makeReference('encounter', $appointmentId)
                ],
                'authoredOn' => $encounterStart,
                'requester' => [
                    'reference' => makeReference('practitioner', $doctorId)
                ],
                'dosageInstruction' => [
                    $dosageInstruction
                ]
            ]
        ];

        $comments = cleanText($medicine['comments'] ?? '');

        if ($comments !== '') {
            $medicationRequest['resource']['note'] = [
                [
                    'text' => $comments
                ]
            ];
        }

        if ($qty > 0) {
            $medicationRequest['resource']['dispenseRequest'] = [
                'quantity' => [
                    'value' => $qty
                ]
            ];
        } else {
            $medicationRequest['resource']['dispenseRequest'] = [
                'quantity' => [
                    'value' => 0
                ]
            ];
        }

        $stopDate = cleanText($medicine['stop_date'] ?? '');

        if (sqlDateIsValid($stopDate)) {
            $validityStart = $encounterStart;

            try {
                $stopDateTime = new DateTime(
                    $stopDate . ' 23:59:59',
                    new DateTimeZone('Asia/Kolkata')
                );
                $validityEnd = $stopDateTime->format('Y-m-d\TH:i:sP');

                $medicationRequest['resource']['dispenseRequest']['validityPeriod'] = [
                    'start' => $validityStart,
                    'end' => $validityEnd
                ];
            } catch (Exception $e) {
            }
        }

        $medicationRequests[] = $medicationRequest;
        $medicationReferences[] = [
            'reference' => makeReference('medication-request', $medicineId),
            'type' => 'MedicationRequest'
        ];

        $narrativeLine = $itemName . '; ' . $dosageText;

        if ($qty > 0) {
            $narrativeLine .= '; quantity ' . rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
        }

        $narrativeLines[] = $narrativeLine;
    }

    if (empty($narrativeLines)) {
        $narrativeLines[] = 'No medicines prescribed.';
    }

    $compositionNarrative = implode(' | ', $narrativeLines);

    $composition = [
        'fullUrl' => makeReference('composition-prescription', $prescriptionId),
        'resource' => [
            'resourceType' => 'Composition',
            'id' => $compositionId,
            'meta' => [
                'profile' => [
                    'https://nrces.in/ndhm/fhir/r4/StructureDefinition/PrescriptionRecord'
                ]
            ],
            'language' => 'en-IN',
            'status' => 'final',
            'type' => [
                'coding' => [
                    [
                        'system' => 'http://snomed.info/sct',
                        'code' => '440545006',
                        'display' => 'Prescription record'
                    ]
                ],
                'text' => 'Prescription record'
            ],
            'subject' => [
                'reference' => makeReference('patient', $patientId)
            ],
            'encounter' => [
                'reference' => makeReference('encounter', $appointmentId)
            ],
            'date' => $encounterStart,
            'author' => [
                [
                    'reference' => makeReference('practitioner', $doctorId)
                ]
            ],
            'title' => 'Prescription Record',
            'custodian' => [
                'reference' => makeReference('organization', $hospitalId)
            ],
            'section' => [
                [
                    'title' => 'Prescription',
                    'code' => [
                        'coding' => [
                            [
                                'system' => 'http://snomed.info/sct',
                                'code' => '440545006',
                                'display' => 'Prescription record'
                            ]
                        ],
                        'text' => 'Prescription record'
                    ],
                    'text' => buildNarrative($compositionNarrative),
                    'entry' => $medicationReferences
                ]
            ]
        ]
    ];

    $patientResource = [
        'fullUrl' => makeReference('patient', $patientId),
        'resource' => [
            'resourceType' => 'Patient',
            'id' => $patientResourceId,
            'meta' => [
                'profile' => [
                    'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Patient'
                ]
            ],
            'identifier' => []
        ]
    ];

    if (cleanText($patient['uhid'] ?? '') !== '') {
        $patientResource['resource']['identifier'][] = [
    'use' => 'usual',
    'type' => [
        'coding' => [
            [
                'system' => 'http://terminology.hl7.org/CodeSystem/v2-0203',
                'code' => 'MR',
                'display' => 'Medical record number'
            ]
        ],
        'text' => 'Medical record number'
    ],
    'value' => cleanText($patient['uhid'])
];
    }

    if (cleanText($patient['abha_id'] ?? '') !== '') {
        $patientResource['resource']['identifier'][] = [
    'use' => 'official',
    'type' => [
        'coding' => [
            [
                'system' => 'http://terminology.hl7.org/CodeSystem/v2-0203',
                'code' => 'NI',
                'display' => 'National unique individual identifier'
            ]
        ],
        'text' => 'National unique individual identifier'
    ],
    'system' => 'https://healthid.abdm.gov.in',
    'value' => cleanText($patient['abha_id'])
];
    }

    $nameObject = [
        'use' => 'official',
        'text' => $patientName
    ];

    $lastName = cleanText($patient['last_name'] ?? '');
    $firstName = cleanText($patient['first_name'] ?? '');

    if ($lastName !== '') {
        $nameObject['family'] = $lastName;
    }

    if ($firstName !== '') {
        $nameObject['given'] = [$firstName];
    }

    $patientResource['resource']['name'] = [$nameObject];
    $patientResource['resource']['gender'] = $patientGender;

    $dob = cleanText($patient['dob'] ?? '');

    if (sqlDateIsValid($dob)) {
        $patientResource['resource']['birthDate'] = $dob;
    }

    $mobile = cleanText($patient['mobile'] ?? '');

    if ($mobile !== '') {
        $patientResource['resource']['telecom'] = [
            [
                'system' => 'phone',
                'value' => $mobile,
                'use' => 'mobile'
            ]
        ];
    }

    $practitionerResource = [
        'fullUrl' => makeReference('practitioner', $doctorId),
        'resource' => [
            'resourceType' => 'Practitioner',
            'id' => $practitionerResourceId,
            'meta' => [
                'profile' => [
                    'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Practitioner'
                ]
            ],
            'name' => [
                [
                    'use' => 'official',
                    'text' => $doctorName
                ]
            ]
        ]
    ];

    $licenseNo = cleanText($doctor['license_no'] ?? '');

    if ($licenseNo !== '') {
    $practitionerResource['resource']['identifier'] = [
        [
            'use' => 'official',
            'type' => [
                'coding' => [
                    [
                        'system' => 'http://terminology.hl7.org/CodeSystem/v2-0203',
                        'code' => 'MD',
                        'display' => 'Medical License number'
                    ]
                ],
                'text' => 'Medical License number'
            ],
            'value' => $licenseNo
        ]
    ];
}

    $organizationIdentifier = cleanText(
        $hospital['hospital_code'] ??
        $hospital['code'] ??
        $hospital['hospital_id'] ??
        ''
    );

    $organizationResource = [
        'fullUrl' => makeReference('organization', $hospitalId),
        'resource' => [
            'resourceType' => 'Organization',
            'id' => $organizationResourceId,
            'meta' => [
                'profile' => [
                    'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Organization'
                ]
            ]
        ]
    ];

    if ($organizationIdentifier !== '') {
    $organizationResource['resource']['identifier'] = [
        [
            'use' => 'official',
            'type' => [
                'coding' => [
                    [
                        'system' => 'http://terminology.hl7.org/CodeSystem/v2-0203',
                        'code' => 'XX',
                        'display' => 'Organization identifier'
                    ]
                ],
                'text' => 'Organization identifier'
            ],
            'value' => $organizationIdentifier
        ]
    ];
}

    $organizationResource['resource']['name'] = $hospitalName;

    $encounterResource = [
        'fullUrl' => makeReference('encounter', $appointmentId),
        'resource' => [
            'resourceType' => 'Encounter',
            'id' => $encounterResourceId,
            'meta' => [
                'profile' => [
                    'https://nrces.in/ndhm/fhir/r4/StructureDefinition/Encounter'
                ]
            ],
            'status' => 'finished',
            'class' => [
                'code' => 'AMB',
                'display' => 'Ambulatory',
                'system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode'
            ],
            'subject' => [
                'reference' => makeReference('patient', $patientId)
            ],
            'participant' => [
                [
                    'individual' => [
                        'reference' => makeReference('practitioner', $doctorId)
                    ]
                ]
            ],
            'period' => [
                'start' => $encounterStart,
                'end' => $encounterEnd
            ],
            'serviceProvider' => [
                'reference' => makeReference('organization', $hospitalId)
            ],
            'identifier' => [
                [
                    'value' => '004-' . $appointmentId
                ]
            ]
        ]
    ];

    $entries = [
        $composition,
        $patientResource,
        $practitionerResource,
        $organizationResource,
        $encounterResource
    ];

    foreach ($medicationRequests as $medicationRequest) {
        $entries[] = $medicationRequest;
    }

    $bundleId = 'prescription-' . $appointmentId . '-' . $prescriptionId;

   $bundle = [
    'resourceType' => 'Bundle',
    'id' => $bundleId,
    'meta' => [
        'versionId' => '1',
        'profile' => [
            'https://nrces.in/ndhm/fhir/r4/StructureDefinition/DocumentBundle'
        ]
    ],
    'identifier' => [
        'system' => 'urn:ietf:rfc:3986',
        'value' => 'urn:uuid:' . $bundleId
    ],
    'type' => 'document',
    'timestamp' => $encounterStart,
    'entry' => $entries
];

    return jsonResponse(true, 'Prescription FHIR Bundle generated successfully.', [
        'source' => [
            'prescription_id' => $prescriptionId,
            'appointment_id' => $appointmentId,
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'hospital_id' => $hospitalId
        ],
        'fhir' => [
            'resourceType' => 'Bundle',
            'bundleType' => 'document',
            'bundleId' => $bundleId,
            'entryCount' => count($entries),
            'prescriptionProfile' => 'https://nrces.in/ndhm/fhir/r4/StructureDefinition/PrescriptionRecord'
        ],
        'bundle' => $bundle
    ]);

} catch (Throwable $e) {
    return jsonResponse(false, 'Failed to generate Prescription FHIR Bundle.', [
        'error' => $e->getMessage()
    ]);
}
}
