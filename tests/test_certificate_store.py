import os
import tempfile
import unittest
from pathlib import Path

from certificate_store import secure_certificate_replace


class CertificateStoreTest(unittest.TestCase):
    def test_secure_atomic_replace(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            source = directory / "certificate.tmp"
            destination = directory / "certificate.der"

            content = b"test-certificate-data"
            destination.write_bytes(b"old-certificate-data")
            self.assertTrue(destination.exists())
            source.write_bytes(content)

            secure_certificate_replace(source, destination)

            self.assertFalse(source.exists())
            self.assertTrue(destination.is_file())
            self.assertEqual(destination.read_bytes(), content)
            self.assertEqual(
                os.stat(destination).st_mode & 0o777,
                0o600,
            )


if __name__ == "__main__":
    unittest.main()
