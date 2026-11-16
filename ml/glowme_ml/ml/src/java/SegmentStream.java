import java.io.*;
import java.nio.charset.StandardCharsets;
import vn.pipeline.Annotation;
import vn.pipeline.VnCoreNLP;
import vn.pipeline.Word;

/** UTF-8 line protocol; segment text with exactly the legacy SegmentLines logic. */
public class SegmentStream {
    public static void main(String[] args) throws Exception {
        // VnCoreNLP may print initialization messages to stdout. Reserve the
        // original stream for the protocol and route library output to stderr.
        PrintStream protocol = System.out;
        System.setOut(System.err);
        VnCoreNLP pipeline = new VnCoreNLP(new String[]{"wseg"});
        try (BufferedReader in = new BufferedReader(new InputStreamReader(System.in, StandardCharsets.UTF_8));
             PrintWriter out = new PrintWriter(new OutputStreamWriter(protocol, StandardCharsets.UTF_8), true)) {
            out.println("__READY__");
            String line;
            while ((line = in.readLine()) != null) {
                if (line.trim().isEmpty()) { out.println(""); continue; }
                Annotation ann = new Annotation(line);
                pipeline.annotate(ann);
                StringBuilder sb = new StringBuilder();
                for (Word w : ann.getWords()) {
                    if (sb.length() > 0) sb.append(' ');
                    sb.append(w.getForm());
                }
                out.println(sb.toString());
            }
        }
    }
}
