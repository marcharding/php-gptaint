# Call Scripts
# Rscript spider_plot.R

# Install and load required packages
if (!require(fmsb)) install.packages("fmsb")
library(fmsb)

# Read the CSV file
data <- read.csv("csv/results_total_metrics.csv", sep=";")

# Set row names as the analyzer column
row.names(data) <- data$analyzer

# Remove the analyzer column
data <- data[, -1]

# Add max and min rows required by fmsb
data <- rbind(rep(1,ncol(data)), rep(0,ncol(data)), data)

# Set up the plot
par(mar=c(1,1,1,1))
colors <- c("red", "blue", "green", "orange", "purple", "brown")

# Set the file path for the PDF
pdf_file <- "pdf/spider_plot.pdf"

# Open a PDF device
pdf(pdf_file, width = 16, height = 9)

# Create the spider plot
radarchart(data,
           pcol=colors,
           pfcol=scales::alpha(colors, 0.2),  # Transparent fill
           plty=2,  # Dotted lines for all outlines
           plwd=2,
           cglcol="grey",
           cglty=1,
           axislabcol="grey",
           caxislabels=seq(0,1,0.2),
           cglwd=0.8,
           vlcex=0.8,
           axistype=1,  # Show axis labels
           seg=5,  # 5 segments on each axis
           pdensity=NULL,  # Remove dots at corners
           pangle=NULL,  # Remove dots at corners
           pch=NA)  # Remove dots at the end of lines

# Add a legend with squares
legend("topright",
       legend=row.names(data)[-c(1,2)],
       bty="n",
       pch=15,  # Use squares for legend symbols
       col=colors,
       pt.cex=1.5,  # Adjust size of squares
       text.col="black",
       cex=0.8)  # Adjust text size if needed

# Print the scale
axis_labels <- colnames(data)
text(x=0, y=-1.3, labels=paste("Scale:", paste(axis_labels, collapse=", ")), cex=0.8)

# Save the plot (optional)
dev.off()